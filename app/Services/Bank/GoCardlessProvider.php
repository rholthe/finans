<?php

namespace App\Services\Bank;

use App\Services\Bank\Mapping\BankMappingInterface;
use App\Services\Bank\Mapping\DefaultMapping;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * GoCardless Bank Account Data-implementasjon av BankDataProvider. Porter
 * token-håndtering, requisition-flyt og transaksjonshenting fra referanse-appen,
 * men for én bruker med ett nøkkelpar fra config.
 */
class GoCardlessProvider implements BankDataProvider
{
    public const KEY = 'gocardless';

    private const ACCESS_TOKEN_KEY = 'gocardless_access_token';

    private const REFRESH_TOKEN_KEY = 'gocardless_refresh_token';

    private const TOKEN_LOCK_KEY = 'gocardless_token_lock';

    private string $baseUri;

    private string $secretId;

    private string $secretKey;

    /** @var array{limit: ?int, remaining: ?int, reset_at: ?CarbonImmutable}|null */
    private ?array $lastRateLimit = null;

    public function __construct()
    {
        $this->baseUri = (string) config('gocardless.base_uri');
        $this->secretId = (string) config('gocardless.secret_id');
        $this->secretKey = (string) config('gocardless.secret_key');
    }

    public function getInstitutions(string $country = 'NO'): array
    {
        return $this->request('GET', '/institutions/', ['country' => strtolower($country)])->json();
    }

    public function createConsent(string $institutionId, string $reference): BankConsent
    {
        $requisition = $this->request('POST', '/requisitions/', [
            'redirect' => config('gocardless.redirect_uri'),
            'institution_id' => $institutionId,
            'reference' => $reference,
            'user_language' => 'NO',
        ])->json();

        return new BankConsent(
            id: (string) ($requisition['id'] ?? ''),
            linked: false,
            status: (string) ($requisition['status'] ?? 'CR'),
            link: $requisition['link'] ?? null,
        );
    }

    public function setPsuContext(?string $ipAddress, ?string $userAgent = null): void
    {
        // GoCardless håndterer PSU-konteksten internt; ingen headere trengs her.
    }

    public function completeConsent(array $callback, ?string $consentId): BankConsent
    {
        // GoCardless tildeler requisition-id-en ved opprettelse, så vi henter
        // bare gjeldende status og kontoer.
        return $this->getConsent((string) $consentId);
    }

    public function getConsent(string $consentId): BankConsent
    {
        $requisition = $this->request('GET', "/requisitions/{$consentId}/")->json();
        $status = (string) ($requisition['status'] ?? '—');
        $linked = $status === 'LN';

        return new BankConsent(
            id: $consentId,
            linked: $linked,
            status: $status,
            accountIds: array_values($requisition['accounts'] ?? []),
            // Utløp ligger på det tilknyttede end-user-agreementet; hent det kun
            // for et aktivt samtykke (best-effort, blokkerer ikke synk ved feil).
            expiresAt: $linked ? $this->agreementExpiry($requisition['agreement'] ?? null) : null,
        );
    }

    /**
     * Utløpstidspunkt fra et end-user-agreement: aksepttidspunkt + gyldighetsdager.
     */
    private function agreementExpiry(?string $agreementId): ?CarbonImmutable
    {
        if (! $agreementId) {
            return null;
        }

        try {
            $agreement = $this->request('GET', "/agreements/enduser/{$agreementId}/")->json();
            $accepted = $agreement['accepted'] ?? null;
            $days = (int) ($agreement['access_valid_for_days'] ?? 0);

            if ($accepted && $days > 0) {
                return CarbonImmutable::parse($accepted)->addDays($days);
            }
        } catch (\Throwable $e) {
            Log::warning('Kunne ikke hente GoCardless-agreement for utløp: '.$e->getMessage());
        }

        return null;
    }

    public function deleteConsent(string $consentId): void
    {
        $this->request('DELETE', "/requisitions/{$consentId}/");
    }

    public function callbackReference(array $callback): ?string
    {
        return isset($callback['ref']) ? (string) $callback['ref'] : null;
    }

    public function getAccountDetails(string $accountId): array
    {
        return $this->request('GET', "/accounts/{$accountId}/")->json();
    }

    public function getTransactions(string $accountId, string $institutionId, string $dateFrom): array
    {
        $this->lastRateLimit = null;

        $response = $this->request('GET', "/accounts/{$accountId}/transactions/", ['date_from' => $dateFrom]);
        $this->captureRateLimit($response);

        $mapping = $this->mappingFor($institutionId);
        $booked = $response->json('transactions.booked') ?? [];
        $pending = $response->json('transactions.pending') ?? [];

        return [
            ...array_map(fn (array $raw): NormalizedTransaction => $this->normalize($raw, $mapping, true), $booked),
            ...array_map(fn (array $raw): NormalizedTransaction => $this->normalize($raw, $mapping, false), $pending),
        ];
    }

    public function getBalances(string $accountId): BankBalance
    {
        $balances = $this->request('GET', "/accounts/{$accountId}/balances/")->json('balances') ?? [];

        return BankBalance::fromList(array_map(fn (array $raw): array => [
            'type' => (string) ($raw['balanceType'] ?? ''),
            'amount' => (float) data_get($raw, 'balanceAmount.amount', 0),
            'currency' => data_get($raw, 'balanceAmount.currency'),
        ], $balances));
    }

    public function lastRateLimit(): ?array
    {
        return $this->lastRateLimit;
    }

    /**
     * Normaliser én rå GoCardless-transaksjon.
     *
     * @param  array<string, mixed>  $raw
     */
    private function normalize(array $raw, BankMappingInterface $mapping, bool $booked): NormalizedTransaction
    {
        $info = $mapping->infoString($raw);

        return new NormalizedTransaction(
            externalId: (string) ($raw['internalTransactionId'] ?? $raw['transactionId'] ?? Str::uuid()),
            date: $this->resolveDate($raw),
            amount: (float) data_get($raw, 'transactionAmount.amount', 0),
            currency: (string) data_get($raw, 'transactionAmount.currency', 'NOK'),
            description: $info,
            payee: Str::limit($info, 255, ''),
            memo: $info,
            booked: $booked,
            raw: $raw,
        );
    }

    /**
     * Bokføringsdato hvis ikke i framtiden, ellers verdidato, ellers i dag.
     *
     * @param  array<string, mixed>  $raw
     */
    private function resolveDate(array $raw): string
    {
        $today = CarbonImmutable::today();

        foreach (['bookingDate', 'valueDate'] as $field) {
            if (! empty($raw[$field])) {
                $date = CarbonImmutable::parse($raw[$field]);
                if (! $date->isAfter($today)) {
                    return $date->toDateString();
                }
            }
        }

        return $today->toDateString();
    }

    private function mappingFor(string $institutionId): BankMappingInterface
    {
        $class = config("gocardless.mappings.{$institutionId}");

        if ($class && class_exists($class)) {
            return new $class;
        }

        return new DefaultMapping;
    }

    private function captureRateLimit(Response $response): void
    {
        $remaining = $response->header('HTTP_X_RATELIMIT_ACCOUNT_SUCCESS_REMAINING')
            ?: $response->header('X-RateLimit-Account-Success-Remaining');

        if ($remaining === '' || $remaining === null) {
            return;
        }

        $reset = (int) ($response->header('HTTP_X_RATELIMIT_ACCOUNT_SUCCESS_RESET')
            ?: $response->header('X-RateLimit-Account-Success-Reset')
            ?: 0);

        $limit = $response->header('HTTP_X_RATELIMIT_ACCOUNT_SUCCESS_LIMIT')
            ?: $response->header('X-RateLimit-Account-Success-Limit');

        $this->lastRateLimit = [
            'limit' => $limit !== null && $limit !== '' ? (int) $limit : null,
            'remaining' => (int) $remaining,
            'reset_at' => $reset > 0 ? CarbonImmutable::now()->addSeconds($reset) : null,
        ];
    }

    /**
     * Autentisert request med automatisk token-fornying og én retry ved 401.
     *
     * @param  array<string, mixed>  $data
     */
    private function request(string $method, string $endpoint, array $data = []): Response
    {
        $execute = function () use ($method, $endpoint, $data): Response {
            $request = Http::withToken($this->getToken())->baseUrl($this->baseUri)->timeout(60);

            return match (strtoupper($method)) {
                'POST' => $request->post($endpoint, $data),
                'DELETE' => $request->delete($endpoint),
                default => $request->get($endpoint, $data),
            };
        };

        try {
            return $execute()->throw();
        } catch (RequestException $e) {
            if ($e->response->status() === 401) {
                Log::warning('GoCardless-token ugyldig, fornyer og prøver på nytt.', ['endpoint' => $endpoint]);
                Cache::forget(self::ACCESS_TOKEN_KEY);

                return $execute()->throw();
            }

            throw $e;
        }
    }

    private function getToken(): string
    {
        if (Cache::has(self::ACCESS_TOKEN_KEY)) {
            return Cache::get(self::ACCESS_TOKEN_KEY);
        }

        return Cache::lock(self::TOKEN_LOCK_KEY, 30)->block(20, function (): string {
            if (Cache::has(self::ACCESS_TOKEN_KEY)) {
                return Cache::get(self::ACCESS_TOKEN_KEY);
            }

            if (Cache::has(self::REFRESH_TOKEN_KEY)) {
                try {
                    $refreshed = Http::asForm()->baseUrl($this->baseUri)
                        ->post('/token/refresh/', ['refresh' => Cache::get(self::REFRESH_TOKEN_KEY)])
                        ->throw()
                        ->json();

                    Cache::put(self::ACCESS_TOKEN_KEY, $refreshed['access'], $refreshed['access_expires']);

                    return $refreshed['access'];
                } catch (\Throwable) {
                    Cache::forget(self::REFRESH_TOKEN_KEY);
                }
            }

            return $this->fetchNewTokens();
        });
    }

    private function fetchNewTokens(): string
    {
        $tokens = Http::asForm()->baseUrl($this->baseUri)
            ->post('/token/new/', ['secret_id' => $this->secretId, 'secret_key' => $this->secretKey])
            ->throw()
            ->json();

        if (empty($tokens['access'])) {
            throw new RuntimeException('Fikk ikke gyldig GoCardless-token. Sjekk GOCARDLESS_SECRET_ID/KEY.');
        }

        Cache::put(self::ACCESS_TOKEN_KEY, $tokens['access'], $tokens['access_expires']);
        Cache::put(self::REFRESH_TOKEN_KEY, $tokens['refresh'], $tokens['refresh_expires']);

        return $tokens['access'];
    }
}
