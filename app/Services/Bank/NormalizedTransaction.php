<?php

namespace App\Services\Bank;

/**
 * Normalisert transaksjon fra en bankleverandør, uavhengig av leverandørens
 * råformat. Budsjettlogikken kjenner kun denne formen.
 */
readonly class NormalizedTransaction
{
    /**
     * @param  string  $externalId  Leverandørens unike id (for deduplisering)
     * @param  string  $date  YYYY-MM-DD
     * @param  float  $amount  Signert: positiv = inn, negativ = ut
     * @param  string  $description  Full info-tekst (matchegrunnlag for regelmotoren)
     * @param  bool  $booked  true = bokført, false = reservert (PEND)
     * @param  array<string, mixed>  $raw  Rådata fra leverandøren
     * @param  bool  $syntheticId  true = banken oppga ingen id, så $externalId er
     *                             avledet av innholdet (se EnableBankingProvider)
     */
    public function __construct(
        public string $externalId,
        public string $date,
        public float $amount,
        public string $currency,
        public string $description,
        public ?string $payee,
        public ?string $memo,
        public bool $booked = true,
        public array $raw = [],
        public bool $syntheticId = false,
    ) {}
}
