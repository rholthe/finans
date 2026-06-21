<?php

namespace App\Services\Bank;

use Carbon\CarbonImmutable;

/**
 * Abstraksjon over en bankaggregator (GoCardless, Enable Banking osv.). Ny
 * leverandør = ny klasse som implementerer dette og registreres i
 * {@see BankProviderRegistry}; ingen endring i budsjett- eller synklogikk.
 *
 * Grensesnittet er leverandøruavhengig: samtykkeflyten uttrykkes som «consent»
 * (ikke GoCardless-spesifikk «requisition»), og status normaliseres til
 * {@see BankConsent::$linked} i stedet for leverandørkoder som «LN».
 */
interface BankDataProvider
{
    /**
     * Liste over støttede institusjoner (banker) for et land. Hvert element har
     * minst `id` og `name`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getInstitutions(string $country = 'NO'): array;

    /**
     * Start en samtykkeflyt. Returnert {@see BankConsent} har en `link` brukeren
     * sendes til; `id` kan være tom hvis leverandøren først tildeler den etter
     * godkjenning (da fastsettes den i {@see completeConsent()}).
     */
    public function createConsent(string $institutionId, string $reference): BankConsent;

    /**
     * Sett PSU-konteksten (sluttbrukerens IP/User-Agent) for påfølgende kall.
     * Berlin Group/PSD2 krever `psu-ip-address` for flere ASPSP-er; ved
     * tilstedeværende bruker (attended) er dette sluttbrukerens reelle IP, ved
     * uovervåket synk en konfigurert fallback. Leverandører som ikke trenger
     * det, implementerer en no-op.
     */
    public function setPsuContext(?string $ipAddress, ?string $userAgent = null): void;

    /**
     * Fullfør samtykket etter at brukeren er sendt tilbake (callback). Mottar
     * rå spørringsparametere fra callback-URL-en og den eventuelle consent-id-en
     * fra opprettelsen. Returnerer det koblede samtykket med konto-id-er.
     *
     * @param  array<string, mixed>  $callback
     */
    public function completeConsent(array $callback, ?string $consentId): BankConsent;

    /**
     * Hent gjeldende status og konto-id-er for et eksisterende samtykke (brukes
     * ved synk for å verifisere at koblingen fortsatt er aktiv).
     */
    public function getConsent(string $consentId): BankConsent;

    public function deleteConsent(string $consentId): void;

    /**
     * Trekk ut referansen (CSRF-tokenet) leverandøren ekko-er tilbake i
     * callback-en, slik at den kan sammenlignes med den lagrede i økten.
     *
     * @param  array<string, mixed>  $callback
     */
    public function callbackReference(array $callback): ?string;

    /**
     * Detaljer for en konto. Inneholder minst `iban`.
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
