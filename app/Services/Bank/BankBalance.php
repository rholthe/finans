<?php

namespace App\Services\Bank;

/**
 * Normalisert kontosaldo fra banken, hentet ved hver synk. Bankene oppgir flere
 * saldotyper (Berlin Group / GoCardless `balanceType`, Enable Banking `balance_type`);
 * vi normaliserer til to: `booked` (kun bokførte poster) og `available` (inkl.
 * reserverte). Begge er signert (negativ = gjeld, f.eks. kredittkort). En type kan
 * mangle hos en gitt bank, da er feltet null.
 */
final class BankBalance
{
    public function __construct(
        public readonly ?float $booked,
        public readonly ?float $available,
        public readonly ?string $currency = null,
    ) {}

    /**
     * Velg bokført/tilgjengelig saldo fra en liste rå saldoer. Hvert element har
     * en `type` (leverandørens balanceType/balance_type), et signert `amount` og
     * valgfri `currency`. Typene matches mot prioriterte lister (mest presise
     * først); GoCardless bruker camelCase-typer, Enable Banking Berlin Group-koder,
     * så begge varianter står i hver liste.
     *
     * @param  list<array{type: string, amount: float, currency?: string|null}>  $balances
     */
    public static function fromList(array $balances): self
    {
        $booked = self::pick($balances, [
            'interimBooked', 'closingBooked', 'openingBooked', 'previouslyClosedBooked',
            'ITBD', 'CLBD', 'OPBD', 'PRCD',
        ]);
        $available = self::pick($balances, [
            'interimAvailable', 'forwardAvailable', 'expected', 'closingAvailable', 'openingAvailable',
            'ITAV', 'FWAV', 'XPCD', 'CLAV', 'OPAV',
        ]);

        $currency = ($available ?? $booked)['currency'] ?? null;

        return new self(
            booked: isset($booked) ? (float) $booked['amount'] : null,
            available: isset($available) ? (float) $available['amount'] : null,
            currency: $currency !== null ? (string) $currency : null,
        );
    }

    /**
     * Returner den første saldoen hvis type står tidligst i prioritetslista.
     *
     * @param  list<array{type: string, amount: float, currency?: string|null}>  $balances
     * @param  list<string>  $preferredTypes
     * @return array{type: string, amount: float, currency?: string|null}|null
     */
    private static function pick(array $balances, array $preferredTypes): ?array
    {
        foreach ($preferredTypes as $type) {
            foreach ($balances as $balance) {
                if (($balance['type'] ?? null) === $type) {
                    return $balance;
                }
            }
        }

        return null;
    }
}
