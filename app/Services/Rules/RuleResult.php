<?php

namespace App\Services\Rules;

use App\Enums\RuleTarget;

/**
 * Resultatet av å kjøre regelmotoren mot én transaksjon. Felter er null når
 * ingen regel satte dem (kalleren bruker da sine egne fallback-verdier).
 */
readonly class RuleResult
{
    public function __construct(
        public ?string $payee = null,
        public ?string $memo = null,
        public ?int $categoryId = null,
        public ?int $ruleId = null,
        public RuleTarget $target = RuleTarget::Category,
        public ?int $transferAccountId = null,
    ) {}

    public function matched(): bool
    {
        return $this->ruleId !== null;
    }
}
