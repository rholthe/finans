<?php

namespace App\Services\Bank;

use Carbon\CarbonImmutable;

/**
 * Abstraksjon over en bankaggregator (GoCardless osv.). Ny leverandør = ny
 * klasse som implementerer dette; ingen endring i budsjett- eller synklogikk.
 */
interface BankDataProvider
{
    /**
     * Liste over støttede institusjoner for et land.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getInstitutions(string $country = 'NO'): array;

    /**
     * Opprett en requisition (samtykkeforespørsel). Returnerer minst
     * `id` og `link` (URL brukeren sendes til for å godkjenne).
     *
     * @return array<string, mixed>
     */
    public function createRequisition(string $institutionId, string $reference): array;

    public function deleteRequisition(string $requisitionId): void;

    /**
     * Status for en requisition. Inneholder minst `status` ('LN' = linket)
     * og `accounts` (liste med eksterne konto-id-er).
     *
     * @return array<string, mixed>
     */
    public function getRequisition(string $requisitionId): array;

    /**
     * Detaljer for en konto. Inneholder minst `status` ('READY') og `iban`.
     *
     * @return array<string, mixed>
     */
    public function getAccountDetails(string $accountId): array;

    /**
     * Hent og normaliser bokførte transaksjoner for en konto.
     *
     * @return list<NormalizedTransaction>
     */
    public function getTransactions(string $accountId, string $institutionId, string $dateFrom): array;

    /**
     * Rate-limit-info fra siste getTransactions-kall, eller null hvis ukjent.
     *
     * @return array{limit: ?int, remaining: ?int, reset_at: ?CarbonImmutable}|null
     */
    public function lastRateLimit(): ?array;
}
