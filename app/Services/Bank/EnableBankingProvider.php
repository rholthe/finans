<?php

namespace App\Services\Bank;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Enable Banking-implementasjon av BankDataProvider (gratis tier for personlig,
 * ikke-kommersiell bruk – arvtaker etter Nordigens gamle gratisnivå).
 *
 * Autentisering: Enable Banking bruker ingen token-utveksling, men et selvsignert
 * JWT (RS256) per request, signert med applikasjonens private nøkkel og
 * `kid = application_id`. JWT-et caches ikke her – det er billig å lage og
 * kortlevd.
 *
 * Samtykkeflyt: `POST /auth` gir en URL brukeren sendes til; etter godkjenning
 * kommer brukeren tilbake med `code` + `state`, og `POST /sessions` oppretter en
 * session med konto-id-er (det vi lagrer som consent-id).
 */
class EnableBankingProvider implements BankDataProvider
{
    public const KEY = 'enablebanking';

    private string $baseUri;

    private string $applicationId;

    private string $privateKey;

    private string $redirectUri;

    private string $country;

    /** @var array{limit: ?int, remaining: ?int, reset_at: ?CarbonImmutable}|null */
    private ?array $lastRateLimit = null;

    public function __construct()
    {
        $this->baseUri = (string) config('enablebanking.base_uri');
        $this->applicationId = (string) config('enablebanking.application_id');
        $this->privateKey = $this->resolvePrivateKey();
        $this->redirectUri = (string) config('enablebanking.redirect_uri');
        $this->country = (string) config('enablebanking.country', 'NO');
    }

    public function getInstitutions(string $country = 'NO'): array
    {
        $aspsps = $this->request('GET', '/aspsps', ['country' => strtoupper($country)])->json('aspsps') ?? [];

        // Enable Banking identifiserer banker med navn (+ land), ikke en egen id.
        return array_values(array_map(fn (array $a): array => [
            'id' => (string) ($a['name'] ?? ''),
            'name' => (string) ($a['name'] ?? ''),
            'country' => (string) ($a['country'] ?? $country),
        ], $aspsps));
    }

    public function createConsent(string $institutionId, string $reference): BankConsent
    {
        $response = $this->request('POST', '/auth', [
            'access' => ['valid_until' => CarbonImmutable::now()->addDays(90)->toIso8601String()],
            'aspsp' => ['name' => $institutionId, 'country' => $this->country],
            'state' => $reference,
            'redirect_url' => $this->redirectUri,
            'psu_type' => 'personal',
        ])->json();

        // Session-id-en finnes ikke ennå – den opprettes i completeConsent().
        return new BankConsent(
            id: '',
            linked: false,
            status: 'PENDING',
            link: $response['url'] ?? null,
        );
    }

    public function completeConsent(array $callback, ?string $consentId): BankConsent
    {
        $code = $callback['code'] ?? null;

        if (! $code) {
            throw new RuntimeException('Enable Banking-callback manglet «code».');
        }

        $session = $this->request('POST', '/sessions', ['code' => $code])->json();

        return $this->consentFromSession((string) ($session['session_id'] ?? ''), $session);
    }

    public function getConsent(string $consentId): BankConsent
    {
        $session = $this->request('GET', "/sessions/{$consentId}")->json();

        return $this->consentFromSession($consentId, $session);
    }

    public function deleteConsent(string $consentId): void
    {
        $this->request('DELETE', "/sessions/{$consentId}");
    }

    public function callbackReference(array $callback): ?string
    {
        return isset($callback['state']) ? (string) $callback['state'] : null;
    }

    public function getAccountDetails(string $accountId): array
    {
        $details = $this->request('GET', "/accounts/{$accountId}/details")->json();

        return ['iban' => data_get($details, 'account_id.iban') ?? data_get($details, 'iban')];
    }

    public function getTransactions(string $accountId, string $institutionId, string $dateFrom): array
    {
        $this->lastRateLimit = null;

        $booked = $this->request('GET', "/accounts/{$accountId}/transactions", [
            'date_from' => $dateFrom,
        ])->json('transactions') ?? [];

        return array_values(array_map(
            fn (array $raw): NormalizedTransaction => $this->normalize($raw),
            $booked,
        ));
    }

    public function lastRateLimit(): ?array
    {
        return $this->lastRateLimit;
    }

    /**
     * Bygg et normalisert samtykke fra en session-respons.
     *
     * @param  array<string, mixed>  $session
     */
    private function consentFromSession(string $sessionId, array $session): BankConsent
    {
        $status = (string) ($session['status'] ?? 'AUTHORIZED');

        // `accounts` kan være enten objekter (POST /sessions) eller bare uid-
        // strenger (GET /sessions/{id}), avhengig av endepunkt.
        $accountIds = array_values(array_map(
            fn ($a): string => is_array($a) ? (string) ($a['uid'] ?? $a['account_id'] ?? '') : (string) $a,
            $session['accounts'] ?? [],
        ));

        return new BankConsent(
            id: $sessionId,
            linked: in_array($status, ['AUTHORIZED', 'VALID'], true),
            status: $status,
            accountIds: array_values(array_filter($accountIds)),
        );
    }

    /**
     * Normaliser én rå Enable Banking-transaksjon. Beløpet er usignert med en
     * egen debet/kredit-indikator, og infoteksten bygges fra strukturerte felter.
     *
     * @param  array<string, mixed>  $raw
     */
    private function normalize(array $raw): NormalizedTransaction
    {
        $amount = (float) data_get($raw, 'transaction_amount.amount', 0);
        if (strtoupper((string) ($raw['credit_debit_indicator'] ?? 'DBIT')) === 'DBIT') {
            $amount = -abs($amount);
        }

        $info = $this->infoString($raw);

        return new NormalizedTransaction(
            externalId: (string) ($raw['entry_reference'] ?? $raw['transaction_id'] ?? Str::uuid()),
            date: $this->resolveDate($raw),
            amount: $amount,
            currency: (string) data_get($raw, 'transaction_amount.currency', 'NOK'),
            description: $info,
            payee: Str::limit($info, 255, ''),
            memo: $info,
            raw: $raw,
        );
    }

    /**
     * Motpartsnavn + meldingstekst som payee/memo utledes fra.
     *
     * @param  array<string, mixed>  $raw
     */
    private function infoString(array $raw): string
    {
        $remittance = data_get($raw, 'remittance_information');
        if (is_array($remittance)) {
            $remittance = implode(' ', array_filter($remittance));
        }

        $party = strtoupper((string) ($raw['credit_debit_indicator'] ?? 'DBIT')) === 'DBIT'
            ? data_get($raw, 'creditor.name')
            : data_get($raw, 'debtor.name');

        return trim(implode(' ', array_filter([$party, $remittance]))) ?: 'Ukjent';
    }

    /**
     * Bokføringsdato hvis ikke i framtiden, ellers verdidato, ellers i dag.
     *
     * @param  array<string, mixed>  $raw
     */
    private function resolveDate(array $raw): string
    {
        $today = CarbonImmutable::today();

        foreach (['booking_date', 'value_date', 'transaction_date'] as $field) {
            if (! empty($raw[$field])) {
                $date = CarbonImmutable::parse($raw[$field]);
                if (! $date->isAfter($today)) {
                    return $date->toDateString();
                }
            }
        }

        return $today->toDateString();
    }

    /**
     * Autentisert request med et ferskt, kortlevd JWT som Bearer-token.
     *
     * @param  array<string, mixed>  $data
     */
    private function request(string $method, string $endpoint, array $data = []): Response
    {
        $request = Http::withToken($this->jwt())->baseUrl($this->baseUri)->acceptJson()->timeout(60);

        $response = match (strtoupper($method)) {
            'POST' => $request->post($endpoint, $data),
            'DELETE' => $request->delete($endpoint),
            default => $request->get($endpoint, $data),
        };

        return $response->throw();
    }

    /**
     * Lag et selvsignert RS256-JWT (uten ekstern pakke, via openssl).
     */
    private function jwt(): string
    {
        $now = CarbonImmutable::now();
        $header = ['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $this->applicationId];
        $payload = [
            'iss' => 'enablebanking.com',
            'aud' => 'api.enablebanking.com',
            'iat' => $now->getTimestamp(),
            'exp' => $now->addHour()->getTimestamp(),
        ];

        $segments = [$this->base64Url(json_encode($header)), $this->base64Url(json_encode($payload))];
        $signingInput = implode('.', $segments);

        $signature = '';
        if (! openssl_sign($signingInput, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Kunne ikke signere Enable Banking-JWT. Sjekk ENABLEBANKING_PRIVATE_KEY.');
        }

        return $signingInput.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Hent den private nøkkelen fra config: enten PEM-innholdet direkte eller en
     * filsti.
     */
    private function resolvePrivateKey(): string
    {
        $key = (string) config('enablebanking.private_key');

        if ($key !== '' && ! str_contains($key, 'BEGIN') && is_file($key)) {
            return (string) file_get_contents($key);
        }

        // Tillat \n-escapede nøkler fra .env.
        return str_replace('\\n', "\n", $key);
    }
}
