<?php

namespace App\Services\Bank;

use Carbon\CarbonImmutable;

/**
 * Normalisert samtykke/kobling mot en bank, uavhengig av leverandørens begreper
 * (GoCardless «requisition», Enable Banking «session» osv.). Budsjett- og
 * synklogikken kjenner kun denne formen.
 */
readonly class BankConsent
{
    /**
     * @param  string  $id  Leverandørens persistente id for samtykket (tom streng før det er fullført)
     * @param  bool  $linked  Om samtykket er aktivt og klart til synk
     * @param  string  $status  Rå leverandørstatus (kun for visning)
     * @param  string|null  $link  URL brukeren sendes til for å godkjenne (kun ved opprettelse)
     * @param  list<string>  $accountIds  Eksterne konto-id-er knyttet til samtykket
     * @param  CarbonImmutable|null  $expiresAt  Når samtykket utløper (null hvis ukjent)
     */
    public function __construct(
        public string $id,
        public bool $linked,
        public string $status,
        public ?string $link = null,
        public array $accountIds = [],
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
