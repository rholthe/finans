<?php

namespace App\Services\Bank;

use App\Enums\RuleTarget;
use App\Mail\SyncReportMail;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\SyncEvent;
use App\Models\Transaction;
use App\Services\Rules\RuleEngine;
use App\Services\Rules\RuleResult;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Henter transaksjoner fra bankleverandøren og lagrer nye (deduplisert på
 * external_id) på den koblede budsjettkontoen. Logger en SyncEvent og sender
 * rapport-e-post til adressen i config etter hver synk – både ved suksess og feil.
 */
class BankSyncService
{
    /** @var array<int, array{status: string, message: string}> */
    private array $report = [];

    private int $imported = 0;

    private bool $hasErrors = false;

    public function __construct(
        private readonly BankProviderRegistry $providers,
        private readonly RuleEngine $rules,
    ) {}

    /**
     * Opprett en SyncEvent (status «processing») og kjør synken. Brukes når vi
     * ikke har en forhåndsopprettet event (CLI/tester).
     *
     * @param  int|null  $days  Antall dager bakover; default ut fra trigger.
     */
    public function sync(?int $days = null, string $trigger = 'manual'): SyncEvent
    {
        $days ??= $trigger === 'auto' ? AppSettings::autoSyncDays() : AppSettings::manualSyncDays();

        $event = SyncEvent::create([
            'status' => SyncEvent::STATUS_PROCESSING,
            'trigger' => $trigger,
            'days_synced' => $days,
        ]);

        $this->runInto($event, $days);

        return $event;
    }

    /**
     * Kjør synken inn i en allerede opprettet (processing) SyncEvent, og
     * finaliser den. Brukes av den køede jobben.
     */
    public function runInto(SyncEvent $event, int $days): void
    {
        $this->report = [];
        $this->imported = 0;
        $this->hasErrors = false;

        $dateFrom = now()->subDays($days)->toDateString();
        $criticalError = null;

        try {
            $connections = BankConnection::with('bankAccounts')->get();

            if ($connections->isEmpty()) {
                $this->line('info', __('Ingen tilkoblede banker.'));
            }

            // Dedup-sett per budsjettkonto («account_id:external_id»). Samme
            // external_id kan gjelde ulike kontoer (sandbox gjenbruker dem, og
            // generelt hører en transaksjon til én konto), så dedup må være
            // kontospesifikk – ikke global.
            $seen = [];
            Transaction::query()
                ->whereNotNull('external_id')
                ->get(['account_id', 'external_id'])
                ->each(function (Transaction $t) use (&$seen): void {
                    $seen[$t->account_id.':'.$t->external_id] = 1;
                });

            foreach ($connections as $connection) {
                $this->syncConnection($connection, $dateFrom, $seen);
            }
        } catch (Throwable $e) {
            $criticalError = $e;
            $this->line('error', __('Kritisk feil under synk: :error', ['error' => $e->getMessage()]));
            Log::error('Banksynk feilet', ['exception' => $e]);
        }

        $status = match (true) {
            $criticalError !== null => SyncEvent::STATUS_FAILED,
            $this->hasErrors => SyncEvent::STATUS_WITH_ERRORS,
            $this->imported > 0 => SyncEvent::STATUS_NEW,
            default => SyncEvent::STATUS_NO_NEW,
        };

        $event->update([
            'status' => $status,
            'imported_count' => $this->imported,
            'days_synced' => $days,
            'report' => $this->report,
        ]);

        $this->sendReport($event);
    }

    /**
     * @param  array<string, int>  $seen
     */
    private function syncConnection(BankConnection $connection, string $dateFrom, array &$seen): void
    {
        $this->line('header', __('Bank: :name', ['name' => $connection->name]));

        $provider = $this->providers->get($connection->provider);

        try {
            $consent = $provider->getConsent($connection->consent_id);
        } catch (Throwable $e) {
            $this->line('error', __('Kunne ikke hente bankstatus: :error', ['error' => $e->getMessage()]));
            $this->hasErrors = true;

            return;
        }

        $connection->status = $consent->status;

        // Oppdater utløp når leverandøren oppgir det; nullstill utløpsvarselet
        // hvis vinduet har flyttet seg (f.eks. etter fornying) så et nytt varsel
        // kan sendes for det nye vinduet.
        if ($consent->expiresAt !== null
            && (! $connection->valid_until || ! $connection->valid_until->equalTo($consent->expiresAt))) {
            $connection->valid_until = $consent->expiresAt;
            $connection->expiry_notified_at = null;
        }

        $connection->save();

        if (! $consent->linked) {
            $this->line('warn', __('Hopper over: tilkoblingen er ikke linket (status: :status).', [
                'status' => $consent->status,
            ]));
            $this->hasErrors = true;

            return;
        }

        foreach ($connection->bankAccounts as $bankAccount) {
            $this->syncAccount($provider, $connection, $bankAccount, $dateFrom, $seen);
        }
    }

    /**
     * @param  array<string, int>  $seen
     */
    private function syncAccount(BankDataProvider $provider, BankConnection $connection, BankAccount $bankAccount, string $dateFrom, array &$seen): void
    {
        if ($bankAccount->account_id === null) {
            $this->line('info', __('Hopper over konto :iban: ikke koblet til en budsjettkonto.', ['iban' => $bankAccount->displayName()]));

            return;
        }

        if ($bankAccount->ignored) {
            $this->line('info', __('Hopper over konto :iban: ignorert.', ['iban' => $bankAccount->displayName()]));

            return;
        }

        if (! $bankAccount->isSyncable()) {
            $this->line('warn', __('Hopper over konto :iban: rate-limit nådd, prøv igjen senere.', ['iban' => $bankAccount->displayName()]));
            $this->hasErrors = true;

            return;
        }

        try {
            $transactions = $provider->getTransactions($bankAccount->external_id, $connection->institution_id, $dateFrom);
        } catch (BankRateLimitException $e) {
            // 429: marker kontoen ikke-synkbar (gjenbruker rate-limit-gatingen)
            // fram til Retry-After, ellers et døgn fram (EB har døgnkvote).
            $resetAt = $e->retryAt ?? now()->addDay();
            $bankAccount->update([
                'rate_limit' => null,
                'rate_limit_remaining' => 0,
                'rate_limit_reset_at' => $resetAt,
            ]);
            $this->line('warn', __('Rate-limit (429) for konto :iban – hopper over til :reset.', [
                'iban' => $bankAccount->displayName(),
                'reset' => $resetAt->toDateTimeString(),
            ]));
            $this->hasErrors = true;

            return;
        } catch (Throwable $e) {
            $this->line('error', __('Kunne ikke hente transaksjoner: :error', ['error' => $e->getMessage()]));
            $this->hasErrors = true;

            return;
        }

        $this->storeRateLimit($provider, $bankAccount);
        $this->storeBalance($provider, $bankAccount);

        // Reserverte (PEND) poster mangler stabil external_id og endrer seg
        // mellom synk-er, så de dedup'es ikke på id. I stedet erstattes kontoens
        // reserverte rader med dagens reserverte sett ved hver synk: en reservert
        // post som er bokført siden sist forsvinner her og kommer inn igjen som
        // booket nedenfor – altså «oppdatert ved bokføring».
        //
        // Også *låste* reserverte rader fjernes: en reservert rad blir låst hvis
        // brukeren kategoriserer den manuelt før den bokføres, men Enable Banking
        // gir bokført-versjonen en ny external_id (transaction_id vs entry_reference),
        // så dedupen kjenner den ikke igjen og importerer den på nytt. Beholdt vi den
        // låste reserverte raden, ville den bli foreldreløs som duplikat. Reserverte
        // rader er kortlevde og erstattes uansett av bokføringen, så vi prioriterer
        // å unngå duplikater framfor å bevare en manuell kategorisering på en rad som
        // straks forsvinner (bokført-raden re-kategoriseres av reglene).
        Transaction::query()
            ->where('account_id', $bankAccount->account_id)
            ->where('pending', true)
            ->delete();

        $newBooked = 0;
        $pendingCount = 0;

        foreach ($transactions as $transaction) {
            if ($transaction->booked) {
                $key = $bankAccount->account_id.':'.$transaction->externalId;
                if (isset($seen[$key])) {
                    continue;
                }

                $this->createFromBank($bankAccount, $transaction, booked: true);
                $seen[$key] = 1;
                $this->imported++;
                $newBooked++;
            } else {
                $this->createFromBank($bankAccount, $transaction, booked: false);
                $pendingCount++;
            }
        }

        $this->line(
            $newBooked > 0 ? 'success' : 'info',
            __(':count nye transaksjon(er) for konto :iban (:pending reservert).', [
                'count' => $newBooked,
                'pending' => $pendingCount,
                'iban' => $bankAccount->displayName(),
            ])
        );
    }

    /**
     * Opprett en transaksjon fra en normalisert bankpost. Bokførte er klarert
     * (teller i avstemming); reserverte er pending=true / cleared=false.
     */
    private function createFromBank(BankAccount $bankAccount, NormalizedTransaction $transaction, bool $booked): void
    {
        $rule = $this->rules->apply($transaction->description, $transaction->amount);

        // Overføringsregel: gjør den bokførte raden om til et overføringspar mot
        // en (ikke-synket) konto. Reserverte konverteres ikke – de churner og
        // konverteres når de bokføres.
        if ($booked && $rule->target === RuleTarget::Transfer && $rule->transferAccountId !== null) {
            $target = Account::find($rule->transferAccountId);
            if ($target !== null) {
                $this->createTransferFromBank($bankAccount, $transaction, $rule, $target);

                return;
            }
        }

        Transaction::create([
            'account_id' => $bankAccount->account_id,
            'category_id' => $rule->target === RuleTarget::Rta ? null : $rule->categoryId,
            'rta' => $rule->target === RuleTarget::Rta,
            'external_id' => $transaction->externalId,
            'bank_description' => $transaction->description,
            'rule_id' => $rule->ruleId,
            'date' => $transaction->date,
            'amount' => $transaction->amount,
            'payee' => $rule->payee ?? $transaction->payee,
            'memo' => $rule->memo ?? $transaction->memo,
            'cleared' => $booked,
            'pending' => ! $booked,
        ]);
    }

    /**
     * Materialiser en overføringsregel som to sammenkoblede ben. Bank-benet
     * (på den synkede kontoen) beholder external_id/bank_description for dedup;
     * motpart-benet opprettes på målkontoen. Kategori/RTA følger budsjett↔
     * overvåket-reglene (samme som TransferService), anvendt på budsjett-benet.
     */
    private function createTransferFromBank(BankAccount $bankAccount, NormalizedTransaction $transaction, RuleResult $rule, Account $target): void
    {
        $bankAcc = $bankAccount->account;
        $amount = $transaction->amount;
        $bankIsFrom = $amount < 0; // negativt = penger ut av bankkontoen

        $from = $bankIsFrom ? $bankAcc : $target;
        $to = $bankIsFrom ? $target : $bankAcc;
        $budgetOutflow = $from->on_budget && ! $to->on_budget;
        $budgetInflow = ! $from->on_budget && $to->on_budget;

        $bankLeg = $bankAcc->transactions()->create([
            'external_id' => $transaction->externalId,
            'bank_description' => $transaction->description,
            'rule_id' => $rule->ruleId,
            'date' => $transaction->date,
            'amount' => $amount,
            'payee' => $bankIsFrom ? "Overføring til {$target->name}" : "Overføring fra {$target->name}",
            'memo' => $rule->memo,
            'cleared' => true,
            'locked' => true,
            'category_id' => ($budgetOutflow && $bankIsFrom) ? $rule->categoryId : null,
            'rta' => $budgetInflow && ! $bankIsFrom,
        ]);

        $oppositeLeg = $target->transactions()->create([
            'date' => $transaction->date,
            'amount' => -$amount,
            'payee' => $bankIsFrom ? "Overføring fra {$bankAcc->name}" : "Overføring til {$bankAcc->name}",
            'transfer_id' => $bankLeg->id,
            'cleared' => true,
            'locked' => true,
            'category_id' => ($budgetOutflow && ! $bankIsFrom) ? $rule->categoryId : null,
            'rta' => $budgetInflow && $bankIsFrom,
        ]);

        $bankLeg->update(['transfer_id' => $oppositeLeg->id]);
    }

    /**
     * Hent og lagre bankens egen saldo (bokført + inkl. reservert). Saldo er
     * sekundært til transaksjonene, så en feil her logges som advarsel uten å
     * markere hele synken som feilet.
     */
    private function storeBalance(BankDataProvider $provider, BankAccount $bankAccount): void
    {
        try {
            $balance = $provider->getBalances($bankAccount->external_id);
        } catch (Throwable $e) {
            $this->line('warn', __('Kunne ikke hente saldo for konto :iban: :error', [
                'iban' => $bankAccount->displayName(),
                'error' => $e->getMessage(),
            ]));

            return;
        }

        $bankAccount->update([
            'balance_booked' => $balance->booked,
            'balance_available' => $balance->available,
            'balance_synced_at' => now(),
        ]);
    }

    private function storeRateLimit(BankDataProvider $provider, BankAccount $bankAccount): void
    {
        $rateLimit = $provider->lastRateLimit();

        // Leverandøren oppgir rate-limit-tall (GoCardless): lagre dem.
        if ($rateLimit !== null) {
            $bankAccount->update([
                'rate_limit' => $rateLimit['limit'],
                'rate_limit_remaining' => $rateLimit['remaining'],
                'rate_limit_reset_at' => $rateLimit['reset_at'],
            ]);

            return;
        }

        // Leverandøren oppgir ingen tall (Enable Banking): en vellykket henting
        // beviser at kvoten ikke er oppbrukt, så nullstill en eventuell utdatert
        // 429-markering. Uten dette ville «0 synk igjen i dag» henge igjen for
        // alltid, siden EB aldri sender tall som overskriver remaining=0.
        if ($bankAccount->rate_limit_remaining !== null) {
            $bankAccount->update([
                'rate_limit' => null,
                'rate_limit_remaining' => null,
                'rate_limit_reset_at' => null,
            ]);
        }
    }

    private function sendReport(SyncEvent $event): void
    {
        $email = AppSettings::reportEmail();

        if (empty($email)) {
            Log::info('Ingen rapport-e-post satt (innstilling/BANK_SYNC_REPORT_EMAIL) – hopper over rapport-e-post.');

            return;
        }

        try {
            Mail::to($email)->send(new SyncReportMail($event));
        } catch (Throwable $e) {
            Log::error('Kunne ikke sende synk-rapport: '.$e->getMessage());
        }
    }

    private function line(string $status, string $message): void
    {
        $this->report[] = ['status' => $status, 'message' => $message];
    }
}
