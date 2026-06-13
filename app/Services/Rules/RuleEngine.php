<?php

namespace App\Services\Rules;

use App\Models\Rule;
use Illuminate\Support\Collection;

/**
 * Leverandøruavhengig regelmotor: tar info-teksten fra en hvilken som helst
 * bank-provider og avgjør payee, memo og kategori. Første matchende regel
 * (etter prioritet) vinner og kan sette flere felter samtidig.
 */
class RuleEngine
{
    /** @var Collection<int, Rule>|null */
    private ?Collection $rules = null;

    public function apply(string $description, float $amount): RuleResult
    {
        foreach ($this->rules() as $rule) {
            if (! $rule->matches($description, $amount)) {
                continue;
            }

            $rule->forceFill(['last_applied_at' => now()])->saveQuietly();

            return new RuleResult(
                payee: $rule->set_payee,
                memo: $rule->set_memo,
                categoryId: $rule->category_id,
                ruleId: $rule->id,
            );
        }

        return new RuleResult;
    }

    /**
     * Aktive regler sortert på prioritet. Caches innen samme kjøring (synk),
     * så en full import ikke laster regelsettet på nytt per transaksjon.
     *
     * @return Collection<int, Rule>
     */
    private function rules(): Collection
    {
        return $this->rules ??= Rule::query()
            ->where('active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * Tøm regel-cachen (etter at regler er endret midt i en prosess).
     */
    public function refresh(): void
    {
        $this->rules = null;
    }
}
