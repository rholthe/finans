<?php

namespace Tests\Support;

use App\Services\Bank\BankConsent;
use App\Services\Bank\BankDataProvider;
use App\Services\Bank\BankRateLimitException;
use App\Services\Bank\NormalizedTransaction;
use Carbon\CarbonImmutable;

/**
 * Konfigurerbar test-double for BankDataProvider. Lar oss teste synk- og
 * tilkoblingslogikk uten å treffe et ekte API. `consents` (tidl. requisitions)
 * holder status + konto-id-er per consent-id.
 */
class FakeBankProvider implements BankDataProvider
{
    /** @var array<int, array<string, mixed>> */
    public array $institutions = [];

    /** @var array<string, array<string, mixed>> */
    public array $consents = [];

    /** @var array<string, array<string, mixed>> */
    public array $accountDetails = [];

    /** @var array<string, list<NormalizedTransaction>> */
    public array $transactions = [];

    /**
     * Konto-id-er som skal svare 429. Verdien er valgfritt Retry-After-tidspunkt.
     *
     * @var array<string, ?CarbonImmutable>
     */
    public array $rateLimited = [];

    /** @var array{limit: ?int, remaining: ?int, reset_at: ?CarbonImmutable}|null */
    public ?array $rateLimit = null;

    public function getInstitutions(string $country = 'NO'): array
    {
        return $this->institutions;
    }

    public function createConsent(string $institutionId, string $reference): BankConsent
    {
        return new BankConsent(
            id: 'consent_'.$institutionId,
            linked: false,
            status: 'CR',
            link: 'https://example.test/link',
        );
    }

    public function completeConsent(array $callback, ?string $consentId): BankConsent
    {
        return $this->getConsent((string) $consentId);
    }

    public function getConsent(string $consentId): BankConsent
    {
        $data = $this->consents[$consentId] ?? ['status' => 'LN', 'accounts' => []];
        $status = (string) ($data['status'] ?? 'LN');

        return new BankConsent(
            id: $consentId,
            linked: $status === 'LN',
            status: $status,
            accountIds: array_values($data['accounts'] ?? []),
        );
    }

    public function deleteConsent(string $consentId): void {}

    public function callbackReference(array $callback): ?string
    {
        return isset($callback['ref'])
            ? (string) $callback['ref']
            : (isset($callback['state']) ? (string) $callback['state'] : null);
    }

    public function getAccountDetails(string $accountId): array
    {
        return $this->accountDetails[$accountId] ?? ['id' => $accountId, 'status' => 'READY', 'iban' => 'NO'.$accountId];
    }

    public function getTransactions(string $accountId, string $institutionId, string $dateFrom): array
    {
        if (array_key_exists($accountId, $this->rateLimited)) {
            throw new BankRateLimitException($this->rateLimited[$accountId]);
        }

        return $this->transactions[$accountId] ?? [];
    }

    public function lastRateLimit(): ?array
    {
        return $this->rateLimit;
    }
}
