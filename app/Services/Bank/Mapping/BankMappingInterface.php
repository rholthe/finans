<?php

namespace App\Services\Bank\Mapping;

/**
 * Per-bank feltmapping for payee/memo-kilden. Banker legger transaksjonsteksten
 * i ulike felter; denne strategien plukker ut riktig kombinasjon.
 */
interface BankMappingInterface
{
    /**
     * Bygg informasjonsstrengen som payee og memo utledes fra.
     *
     * @param  array<string, mixed>  $raw  Rå transaksjonsdata fra leverandøren
     */
    public function infoString(array $raw): string;
}
