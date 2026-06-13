<?php

namespace App\Enums;

enum RuleApplies: string
{
    case Both = 'both';        // Gjelder både inn og ut
    case Inflow = 'inflow';    // Kun innbetalinger (beløp > 0)
    case Outflow = 'outflow';  // Kun utbetalinger (beløp < 0)

    /**
     * Om regelen gjelder en transaksjon med gitt (signert) beløp.
     */
    public function matchesAmount(float $amount): bool
    {
        return match ($this) {
            self::Both => true,
            self::Inflow => $amount > 0,
            self::Outflow => $amount < 0,
        };
    }
}
