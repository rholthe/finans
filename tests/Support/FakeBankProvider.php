<?php

namespace Tests\Support;

use App\Services\Bank\BankDataProvider;
use App\Services\Bank\NormalizedTransaction;
use Carbon\CarbonImmutable;

/**
 * Konfigurerbar test-double for BankDataProvider. Lar oss teste synk- og
 * tilkoblingslogikk uten å treffe et ekte API.
 */
class FakeBankProvider implements BankDataProvider
{
    /** @var array<int, array<string, mixed>> */
    public array $institutions = [];

    /** @var array<string, array<string, mixed>> */
    public array $requisitions = [];

    /** @var array<string, array<string, mixed>> */
    public array $accountDetails = [];

    /** @var array<string, list<NormalizedTransaction>> */
    public array $transactions = [];

    /** @var array{limit: ?int, remaining: ?int, reset_at: ?CarbonImmutable}|null */
    public ?array $rateLimit = null;

    public function getInstitutions(string $country = 'NO'): array
    {
        return $this->institutions;
    }

    public function createRequisition(string $institutionId, string $reference): array
    {
        return ['id' => 'req_'.$institutionId, 'link' => 'https://example.test/link', 'institution_id' => $institutionId];
    }

    public function deleteRequisition(string $requisitionId): void {}

    public function getRequisition(string $requisitionId): array
    {
        return $this->requisitions[$requisitionId] ?? ['status' => 'LN', 'accounts' => []];
    }

    public function getAccountDetails(string $accountId): array
    {
        return $this->accountDetails[$accountId] ?? ['id' => $accountId, 'status' => 'READY', 'iban' => 'NO'.$accountId];
    }

    public function getTransactions(string $accountId, string $institutionId, string $dateFrom): array
    {
        return $this->transactions[$accountId] ?? [];
    }

    public function lastRateLimit(): ?array
    {
        return $this->rateLimit;
    }
}
