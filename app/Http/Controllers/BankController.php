<?php

namespace App\Http\Controllers;

use App\Jobs\SyncBankTransactionsJob;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\SyncEvent;
use App\Services\Bank\BankConsent;
use App\Services\Bank\BankDataProvider;
use App\Services\Bank\BankProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class BankController extends Controller
{
    public function __construct(private readonly BankProviderRegistry $providers) {}

    /**
     * Liste over institusjoner (banker) for et land, for en valgt leverandør.
     */
    public function institutions(Request $request): JsonResponse
    {
        $country = $request->string('country', 'NO')->upper()->value();
        $providerKey = $request->string('provider')->value() ?: BankProviderRegistry::DEFAULT;

        if (! $this->providers->isValid($providerKey)) {
            return response()->json(['message' => 'Ukjent bankleverandør.'], 422);
        }

        return response()->json($this->providers->get($providerKey)->getInstitutions($country));
    }

    /**
     * Tilkoblede banker med kontoer og koblingsstatus.
     */
    public function connections(): JsonResponse
    {
        $connections = BankConnection::with('bankAccounts')->orderBy('name')->get()
            ->map(fn (BankConnection $c): array => [
                'id' => $c->id,
                'provider' => $c->provider,
                'name' => $c->name,
                'institution_id' => $c->institution_id,
                'status' => $c->status,
                'valid_until' => $c->valid_until?->toIso8601String(),
                'accounts' => $c->bankAccounts->map(fn (BankAccount $a): array => [
                    'id' => $a->id,
                    'external_id' => $a->external_id,
                    'name' => $a->name,
                    'iban' => $a->iban,
                    'account_id' => $a->account_id,
                    'ignored' => $a->ignored,
                    'rate_limit' => $a->rate_limit,
                    'rate_limit_remaining' => $a->rate_limit_remaining,
                    'rate_limit_reset_at' => $a->rate_limit_reset_at?->toIso8601String(),
                ])->all(),
            ]);

        return response()->json(['data' => $connections]);
    }

    /**
     * Start en banktilkobling: opprett et samtykke hos valgt leverandør og
     * returner lenken brukeren sendes til. Referansen lagres i økten for
     * CSRF-verifisering i callback.
     */
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'institution_id' => ['required', 'string'],
            'provider' => ['sometimes', Rule::in($this->providers->keys())],
        ]);

        $providerKey = $validated['provider'] ?? BankProviderRegistry::DEFAULT;
        $reference = (string) Str::uuid();
        $provider = $this->providers->get($providerKey);
        $provider->setPsuContext($request->ip(), $request->userAgent());
        $consent = $provider->createConsent($validated['institution_id'], $reference);

        $request->session()->put('bank_ref', $reference);
        $request->session()->put('bank_provider', $providerKey);
        $request->session()->put('bank_consent_id', $consent->id);
        $request->session()->put('bank_institution_id', $validated['institution_id']);

        return response()->json(['link' => $consent->link]);
    }

    /**
     * Forny en eksisterende banktilkobling: start en ny samtykkeflyt for samme
     * bank/leverandør, men merk økten slik at callback gjenbruker tilkoblingen
     * (og beholder kontokoblingene) i stedet for å opprette en ny.
     */
    public function renew(Request $request, BankConnection $bankConnection): JsonResponse
    {
        $provider = $this->providers->get($bankConnection->provider);
        $provider->setPsuContext($request->ip(), $request->userAgent());
        $reference = (string) Str::uuid();
        $consent = $provider->createConsent($bankConnection->institution_id, $reference);

        $request->session()->put('bank_ref', $reference);
        $request->session()->put('bank_provider', $bankConnection->provider);
        $request->session()->put('bank_consent_id', $consent->id);
        $request->session()->put('bank_institution_id', $bankConnection->institution_id);
        $request->session()->put('bank_renew_connection_id', $bankConnection->id);

        return response()->json(['link' => $consent->link]);
    }

    /**
     * Callback fra GoCardless (topp-nivå nettlesernavigasjon). Verifiserer
     * referansen, lagrer bank + kontoer, og sender brukeren til SPA-en.
     */
    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('bank_ref');
        $providerKey = $request->session()->pull('bank_provider', BankProviderRegistry::DEFAULT);
        $consentId = $request->session()->pull('bank_consent_id');
        $institutionId = $request->session()->pull('bank_institution_id');
        $renewId = $request->session()->pull('bank_renew_connection_id');

        if (! $this->providers->isValid($providerKey) || ! $institutionId) {
            return redirect('/bank?status=error&reason=session');
        }

        $provider = $this->providers->get($providerKey);
        $provider->setPsuContext($request->ip(), $request->userAgent());
        $reference = $provider->callbackReference($request->query());

        if (! $reference || ! $expected || ! hash_equals($expected, $reference)) {
            return redirect('/bank?status=error&reason=token');
        }

        try {
            $consent = $provider->completeConsent($request->query(), $consentId ?: null);

            // Fornying gjenbruker en eksisterende tilkobling og beholder
            // budsjettkoblingene; ny tilkobling oppretter alt fra bunnen.
            return $renewId
                ? $this->completeRenewal((int) $renewId, $provider, $consent)
                : $this->completeNewConnection($provider, $providerKey, $institutionId, $consent);
        } catch (Throwable $e) {
            Log::error('Banktilkobling feilet i callback', ['exception' => $e]);

            return redirect('/bank?status=error&reason=api');
        }
    }

    /**
     * Lagre en helt ny banktilkobling med kontoer fra et fullført samtykke.
     */
    private function completeNewConnection(
        BankDataProvider $provider,
        string $providerKey,
        string $institutionId,
        BankConsent $consent,
    ): RedirectResponse {
        // Dupliseringssjekk: avbryt hvis en av kontoene allerede finnes.
        if (BankAccount::whereIn('external_id', $consent->accountIds)->exists()) {
            return redirect('/bank?status=error&reason=duplicate');
        }

        $name = collect($provider->getInstitutions('NO'))
            ->firstWhere('id', $institutionId)['name'] ?? $institutionId;

        $connection = BankConnection::create([
            'provider' => $providerKey,
            'institution_id' => $institutionId,
            'name' => $name,
            'consent_id' => $consent->id,
            'status' => $consent->status,
            'valid_until' => $consent->expiresAt,
        ]);

        foreach ($consent->accountIds as $accountId) {
            $details = $provider->getAccountDetails($accountId);
            $connection->bankAccounts()->create([
                'external_id' => $accountId,
                'iban' => $details['iban'] ?? data_get($details, 'account.iban'),
            ]);
        }

        return redirect('/bank?status=connected');
    }

    /**
     * Fullfør en fornying: oppdater consent/utløp på den eksisterende tilkoblingen
     * og re-map nye eksterne konto-id-er til eksisterende bankkontoer via IBAN, slik
     * at koblingene til budsjettkontoer overlever (leverandøren kan gi nye konto-id-er).
     */
    private function completeRenewal(
        int $connectionId,
        BankDataProvider $provider,
        BankConsent $consent,
    ): RedirectResponse {
        $connection = BankConnection::with('bankAccounts')->find($connectionId);

        if (! $connection) {
            return redirect('/bank?status=error&reason=session');
        }

        // De nye konto-id-ene må ikke allerede tilhøre en annen tilkobling.
        $clashes = BankAccount::whereIn('external_id', $consent->accountIds)
            ->where('bank_connection_id', '!=', $connection->id)
            ->exists();

        if ($clashes) {
            return redirect('/bank?status=error&reason=duplicate');
        }

        $connection->update([
            'consent_id' => $consent->id,
            'status' => $consent->status,
            'valid_until' => $consent->expiresAt,
            'expiry_notified_at' => null,
        ]);

        $existingByIban = $connection->bankAccounts->keyBy('iban');

        foreach ($consent->accountIds as $accountId) {
            $details = $provider->getAccountDetails($accountId);
            $iban = $details['iban'] ?? data_get($details, 'account.iban');

            $match = $iban ? $existingByIban->get($iban) : null;

            if ($match) {
                $match->update(['external_id' => $accountId, 'iban' => $iban]);
            } else {
                $connection->bankAccounts()->create(['external_id' => $accountId, 'iban' => $iban]);
            }
        }

        return redirect('/bank?status=renewed');
    }

    /**
     * Koble en bankkonto til en budsjettkonto (eller ignorer den), og sett et
     * valgfritt visningsnavn. Tomt navn nullstilles (fall tilbake på iban/id).
     */
    public function linkAccount(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['nullable', Rule::exists('accounts', 'id')],
            'ignored' => ['sometimes', 'boolean'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('name', $validated)) {
            $validated['name'] = filled($validated['name']) ? trim($validated['name']) : null;
        }

        $bankAccount->update($validated);

        return response()->json(['data' => $bankAccount->only(['id', 'account_id', 'ignored', 'name'])]);
    }

    /**
     * Sett et visningsnavn på en banktilkobling (brukes i frontend og e-poster).
     */
    public function renameConnection(Request $request, BankConnection $bankConnection): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $bankConnection->update(['name' => trim($validated['name'])]);

        return response()->json(['data' => $bankConnection->only(['id', 'name'])]);
    }

    /**
     * Slett en banktilkobling (og dens requisition hos leverandøren).
     */
    public function deleteConnection(BankConnection $bankConnection): JsonResponse
    {
        if ($bankConnection->consent_id) {
            try {
                $this->providers->get($bankConnection->provider)->deleteConsent($bankConnection->consent_id);
            } catch (Throwable $e) {
                Log::warning('Kunne ikke slette samtykket hos leverandøren: '.$e->getMessage());
            }
        }

        $bankConnection->delete();

        return response()->json(status: 204);
    }

    /**
     * Start en manuell synk: opprett en processing-event og legg jobben i kø.
     * Frontend poller status via syncStatus().
     */
    public function sync(): JsonResponse
    {
        $event = SyncEvent::create([
            'status' => SyncEvent::STATUS_PROCESSING,
            'trigger' => 'manual',
        ]);

        SyncBankTransactionsJob::dispatch($event->id, 'manual');

        return response()->json($this->eventPayload($event), 202);
    }

    /**
     * Status for en synk-hendelse (for polling fra frontend).
     */
    public function syncStatus(SyncEvent $syncEvent): JsonResponse
    {
        return response()->json($this->eventPayload($syncEvent));
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(SyncEvent $event): array
    {
        return [
            'id' => $event->id,
            'status' => $event->status,
            'trigger' => $event->trigger,
            'imported_count' => $event->imported_count,
            'report' => $event->report,
            'finished' => $event->status !== SyncEvent::STATUS_PROCESSING,
        ];
    }
}
