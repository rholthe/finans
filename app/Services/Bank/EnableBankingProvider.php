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

    private ?string $psuIpAddress;

    private ?string $psuUserAgent;

    /** @var array{limit: ?int, remaining: ?int, reset_at: ?CarbonImmutable}|null */
    private ?array $lastRateLimit = null;

    public function __construct()
    {
        $this->baseUri = (string) config('enablebanking.base_uri');
        $this->applicationId = (string) config('enablebanking.application_id');
        $this->privateKey = $this->resolvePrivateKey();
        $this->redirectUri = (string) config('enablebanking.redirect_uri');
        $this->country = (string) config('enablebanking.country', 'NO');

        // Fallback-PSU-kontekst for uovervåket synk (cron), der ingen sluttbruker
        // er til stede; attended-flyter overstyrer via setPsuContext().
        $this->psuIpAddress = ((string) config('enablebanking.psu_ip')) ?: null;
        $this->psuUserAgent = ((string) config('enablebanking.psu_user_agent')) ?: null;
    }

    public function setPsuContext(?string $ipAddress, ?string $userAgent = null): void
    {
        if ($ipAddress !== null && $ipAddress !== '') {
            $this->psuIpAddress = $ipAddress;
        }

        if ($userAgent !== null && $userAgent !== '') {
            $this->psuUserAgent = $userAgent;
        }
    }

    /**
     * Rå ASPSP-metadata fra Enable Banking (uavkortet), til feilsøking av
     * leverandørspesifikke samtykke-/tilgangskrav (f.eks. `required_psu_headers`,
     * `maximum_consent_validity`, `psu_types`). Returnerer hele bankobjektet slik
     * EB oppgir det, i motsetning til den normaliserte `getInstitutions()`.
     *
     * @return list<array<string, mixed>>
     */
    public function getInstitutionsRaw(string $country = 'NO'): array
    {
        return array_values($this->request('GET', '/aspsps', ['country' => strtoupper($country)])->json('aspsps') ?? []);
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

        // Uten transaction_status-filter returnerer Enable Banking både bokførte
        // (BOOK) og reserverte (PEND); status leses per rad i normalize().
        $entries = $this->request('GET', "/accounts/{$accountId}/transactions", [
            'date_from' => $dateFrom,
        ])->json('transactions') ?? [];

        return array_values(array_map(
            fn (array $raw): NormalizedTransaction => $this->normalize($raw),
            $entries,
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

        $validUntil = data_get($session, 'access.valid_until');

        return new BankConsent(
            id: $sessionId,
            linked: in_array($status, ['AUTHORIZED', 'VALID'], true),
            status: $status,
            accountIds: array_values(array_filter($accountIds)),
            expiresAt: $validUntil ? CarbonImmutable::parse($validUntil) : null,
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

        // Full info-tekst (regelmatch-grunnlag + memo) og et eget, mer presist
        // motpartsnavn til payee.
        $info = $this->infoString($raw);
        $payee = $this->resolvePayee($raw) ?? Str::limit($info, 255, '');

        return new NormalizedTransaction(
            externalId: (string) ($raw['entry_reference'] ?? $raw['transaction_id'] ?? Str::uuid()),
            date: $this->resolveDate($raw),
            amount: $amount,
            currency: (string) data_get($raw, 'transaction_amount.currency', 'NOK'),
            description: $info,
            payee: Str::limit($payee, 255, ''),
            memo: $info,
            booked: strtoupper((string) ($raw['status'] ?? 'BOOK')) === 'BOOK',
            raw: $raw,
        );
    }

    /**
     * Full info-tekst (motpartsnavn + alle meldingslinjer), med normalisert
     * whitespace. Brukes som memo og som regelmotorens matchegrunnlag, så alt
     * av tekst beholdes – men bankens kolonnepadding kollapses til enkle
     * mellomrom så lengdegrensen ikke spises opp av fyll.
     *
     * @param  array<string, mixed>  $raw
     */
    private function infoString(array $raw): string
    {
        $parts = array_merge([$this->structuredParty($raw)], $this->remittanceLines($raw));
        $info = $this->collapse(implode(' ', array_filter($parts)));

        return $info !== '' ? $info : 'Ukjent';
    }

    /**
     * Motpartsnavn til payee: bruk det strukturerte feltet når banken oppgir det
     * (typisk kortkjøp), ellers trekk navnet ut av «Overføring Innland/Utland,
     * <navn>»-linjen Norske banker legger i meldingsinformasjonen. Null = ingen
     * tydelig motpart funnet (faller tilbake på info-teksten).
     *
     * @param  array<string, mixed>  $raw
     */
    private function resolvePayee(array $raw): ?string
    {
        $structured = $this->collapse((string) $this->structuredParty($raw));
        if ($structured !== '') {
            return $structured;
        }

        foreach ($this->remittanceLines($raw) as $line) {
            if (preg_match('/^Overføring (?:Innland|Utland),\s*(.+)$/iu', $line, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    /**
     * Strukturert motpartsnavn: kreditor ved utbetaling (DBIT), debitor ved
     * innbetaling (CRDT).
     *
     * @param  array<string, mixed>  $raw
     */
    private function structuredParty(array $raw): ?string
    {
        $name = strtoupper((string) ($raw['credit_debit_indicator'] ?? 'DBIT')) === 'DBIT'
            ? data_get($raw, 'creditor.name')
            : data_get($raw, 'debtor.name');

        return $name !== null ? (string) $name : null;
    }

    /**
     * Meldingslinjer, hver med kollapset whitespace og tomme linjer fjernet.
     *
     * @param  array<string, mixed>  $raw
     * @return list<string>
     */
    private function remittanceLines(array $raw): array
    {
        $remittance = data_get($raw, 'remittance_information', []);
        if (! is_array($remittance)) {
            $remittance = [$remittance];
        }

        return array_values(array_filter(array_map(
            fn ($line): string => $this->collapse((string) $line),
            $remittance,
        )));
    }

    /**
     * Kollaps alle whitespace-sekvenser (bankens kolonnepadding) til ett mellomrom.
     */
    private function collapse(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
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
        $request = Http::withToken($this->jwt())
            ->withHeaders($this->psuHeaders())
            ->baseUrl($this->baseUri)
            ->acceptJson()
            ->timeout(60);

        $response = match (strtoupper($method)) {
            'POST' => $request->post($endpoint, $data),
            'DELETE' => $request->delete($endpoint),
            default => $request->get($endpoint, $data),
        };

        // Enable Banking eksponerer ingen rate-limit-headere – kun 429 ved
        // overskridelse. Oversett til en typet exception synken kan bakke av på.
        if ($response->status() === 429) {
            throw new BankRateLimitException($this->retryAtFrom($response));
        }

        return $response->throw();
    }

    /**
     * Tolk Retry-After fra et 429-svar: sekunder eller en HTTP-dato. Enable
     * Banking oppgir den sjelden, så null (= leverandørens standard-backoff)
     * er det vanlige.
     */
    private function retryAtFrom(Response $response): ?CarbonImmutable
    {
        $retryAfter = $response->header('Retry-After');

        if ($retryAfter === null || $retryAfter === '') {
            return null;
        }

        try {
            return is_numeric($retryAfter)
                ? CarbonImmutable::now()->addSeconds((int) $retryAfter)
                : CarbonImmutable::parse($retryAfter);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * PSU-headere (Berlin Group/PSD2) som sendes med hver request når de er
     * tilgjengelige. `psu-ip-address` kreves av flere ASPSP-er (jf. ASPSP-enes
     * `required_psu_headers`); tomme verdier utelates.
     *
     * @return array<string, string>
     */
    private function psuHeaders(): array
    {
        return array_filter([
            'psu-ip-address' => $this->psuIpAddress,
            'psu-user-agent' => $this->psuUserAgent,
        ], fn (?string $value): bool => $value !== null && $value !== '');
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
