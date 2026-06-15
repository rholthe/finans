<?php

namespace App\Services\Bank;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Kastes av en bankleverandør når API-et svarer 429 (rate limit). Bærer et
 * valgfritt tidspunkt for når det er trygt å prøve igjen (fra Retry-After,
 * der leverandøren oppgir det). Synken fanger denne og markerer kontoen
 * ikke-synkbar fram til da, i stedet for å behandle det som en hard feil.
 */
class BankRateLimitException extends RuntimeException
{
    public function __construct(
        public readonly ?CarbonImmutable $retryAt = null,
        string $message = 'Bankens API svarte 429 (rate limit).',
    ) {
        parent::__construct($message);
    }
}
