<?php

namespace App\Services\Rules;

use App\Models\Rule;
use Illuminate\Support\Collection;

/**
 * Leverandøruavhengig regelmotor: tar info-teksten fra en hvilken som helst
 * bank-provider og avgjør payee, memo og kategori. Ved overlapp vinner den
 * mest spesifikke matchende regelen (flest/lengst inneholder-termer); en
 * vinnende regel kan sette flere felter samtidig.
 */
class RuleEngine
{
    /** @var Collection<int, Rule>|null */
    private ?Collection $rules = null;

    public function apply(string $description, float $amount): RuleResult
    {
        $rule = $this->rules()
            ->filter(fn (Rule $rule): bool => $rule->matches($description, $amount))
            // Mest spesifikk vinner: synkende [antall termer, samlet lengde],
            // med lavest id som stabil tie-break.
            ->sort(fn (Rule $a, Rule $b): int => [...$b->specificity(), $a->id] <=> [...$a->specificity(), $b->id])
            ->first();

        if ($rule === null) {
            return new RuleResult;
        }

        $rule->forceFill(['last_applied_at' => now()])->saveQuietly();

        return new RuleResult(
            payee: $rule->set_payee,
            memo: $rule->set_memo,
            categoryId: $rule->category_id,
            ruleId: $rule->id,
            target: $rule->target_type,
            transferAccountId: $rule->transfer_account_id,
        );
    }

    /**
     * Aktive regler. Caches innen samme kjøring (synk), så en full import ikke
     * laster regelsettet på nytt per transaksjon.
     *
     * @return Collection<int, Rule>
     */
    private function rules(): Collection
    {
        return $this->rules ??= Rule::query()
            ->where('active', true)
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
