<?php

namespace App\Services\Bank;

use App\Mail\SyncReportMail;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\SyncEvent;
use App\Models\Transaction;
use App\Services\Rules\RuleEngine;
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
        private readonly BankDataProvider $provider,
        private readonly RuleEngine $rules,
    ) {}

    public function sync(): SyncEvent
    {
        $this->report = [];
        $this->imported = 0;
        $this->hasErrors = false;

        $days = (int) config('gocardless.sync_days');
        $dateFrom = now()->subDays($days)->toDateString();
        $criticalError = null;

        try {
            $connections = BankConnection::with('bankAccounts')->get();

            if ($connections->isEmpty()) {
                $this->line('info', __('Ingen tilkoblede banker.'));
            }

            // Globalt dedup-sett (external_id er unik hos leverandøren).
            $seen = array_flip(
                Transaction::whereNotNull('external_id')->pluck('external_id')->all()
            );

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

        $event = SyncEvent::create([
            'status' => $status,
            'imported_count' => $this->imported,
            'days_synced' => $days,
            'report' => $this->report,
        ]);

        $this->sendReport($event);

        return $event;
    }

    /**
     * @param  array<string, int>  $seen
     */
    private function syncConnection(BankConnection $connection, string $dateFrom, array &$seen): void
    {
        $this->line('header', __('Bank: :name', ['name' => $connection->name]));

        try {
            $requisition = $this->provider->getRequisition($connection->requisition_id);
        } catch (Throwable $e) {
            $this->line('error', __('Kunne ikke hente bankstatus: :error', ['error' => $e->getMessage()]));
            $this->hasErrors = true;

            return;
        }

        $connection->update(['status' => $requisition['status'] ?? $connection->status]);

        if (($requisition['status'] ?? null) !== 'LN') {
            $this->line('warn', __('Hopper over: tilkoblingen er ikke linket (status: :status).', [
                'status' => $requisition['status'] ?? '—',
            ]));
            $this->hasErrors = true;

            return;
        }

        foreach ($connection->bankAccounts as $bankAccount) {
            $this->syncAccount($connection, $bankAccount, $dateFrom, $seen);
        }
    }

    /**
     * @param  array<string, int>  $seen
     */
    private function syncAccount(BankConnection $connection, BankAccount $bankAccount, string $dateFrom, array &$seen): void
    {
        if ($bankAccount->account_id === null) {
            $this->line('info', __('Hopper over konto :iban: ikke koblet til en budsjettkonto.', ['iban' => $bankAccount->iban ?? $bankAccount->external_id]));

            return;
        }

        if ($bankAccount->ignored) {
            $this->line('info', __('Hopper over konto :iban: ignorert.', ['iban' => $bankAccount->iban ?? $bankAccount->external_id]));

            return;
        }

        if (! $bankAccount->isSyncable()) {
            $this->line('warn', __('Hopper over konto :iban: rate-limit nådd, prøv igjen senere.', ['iban' => $bankAccount->iban ?? $bankAccount->external_id]));
            $this->hasErrors = true;

            return;
        }

        try {
            $transactions = $this->provider->getTransactions($bankAccount->external_id, $connection->institution_id, $dateFrom);
        } catch (Throwable $e) {
            $this->line('error', __('Kunne ikke hente transaksjoner: :error', ['error' => $e->getMessage()]));
            $this->hasErrors = true;

            return;
        }

        $this->storeRateLimit($bankAccount);

        $newCount = 0;
        foreach ($transactions as $transaction) {
            if (isset($seen[$transaction->externalId])) {
                continue;
            }

            $rule = $this->rules->apply($transaction->description, $transaction->amount);

            Transaction::create([
                'account_id' => $bankAccount->account_id,
                'category_id' => $rule->categoryId,
                'external_id' => $transaction->externalId,
                'bank_description' => $transaction->description,
                'rule_id' => $rule->ruleId,
                'date' => $transaction->date,
                'amount' => $transaction->amount,
                'payee' => $rule->payee ?? $transaction->payee,
                'memo' => $rule->memo ?? $transaction->memo,
                'cleared' => true,
            ]);

            $seen[$transaction->externalId] = 1;
            $this->imported++;
            $newCount++;
        }

        $this->line(
            $newCount > 0 ? 'success' : 'info',
            __(':count nye transaksjon(er) for konto :iban.', [
                'count' => $newCount,
                'iban' => $bankAccount->iban ?? $bankAccount->external_id,
            ])
        );
    }

    private function storeRateLimit(BankAccount $bankAccount): void
    {
        $rateLimit = $this->provider->lastRateLimit();

        if ($rateLimit === null) {
            return;
        }

        $bankAccount->update([
            'rate_limit' => $rateLimit['limit'],
            'rate_limit_remaining' => $rateLimit['remaining'],
            'rate_limit_reset_at' => $rateLimit['reset_at'],
        ]);
    }

    private function sendReport(SyncEvent $event): void
    {
        $email = config('gocardless.report_email');

        if (empty($email)) {
            Log::info('Ingen BANK_SYNC_REPORT_EMAIL satt – hopper over rapport-e-post.');

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
