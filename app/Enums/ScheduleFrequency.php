<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum ScheduleFrequency: string
{
    case Weekly = 'weekly';             // Ukentlig
    case Biweekly = 'biweekly';         // Hver 2. uke
    case Monthly = 'monthly';           // Månedlig
    case Quarterly = 'quarterly';       // Hver 3. måned
    case SemiAnnually = 'semiannually'; // Hver 6. måned
    case Yearly = 'yearly';             // Årlig

    /**
     * Neste forekomst etter en gitt dato. Måned-baserte frekvenser bruker
     * «no overflow» slik at f.eks. 31. jan blir 28./29. feb, ikke 3. mars.
     */
    public function advance(CarbonImmutable $date): CarbonImmutable
    {
        return match ($this) {
            self::Weekly => $date->addWeek(),
            self::Biweekly => $date->addWeeks(2),
            self::Monthly => $date->addMonthNoOverflow(),
            self::Quarterly => $date->addMonthsNoOverflow(3),
            self::SemiAnnually => $date->addMonthsNoOverflow(6),
            self::Yearly => $date->addYearNoOverflow(),
        };
    }
}
