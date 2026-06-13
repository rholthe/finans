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
     * @param  array<string, mixed>  $raw  Rådata fra leverandøren
     */
    public function __construct(
        public string $externalId,
        public string $date,
        public float $amount,
        public string $currency,
        public ?string $payee,
        public ?string $memo,
        public array $raw = [],
    ) {}
}
