<?php

namespace Database\Factories;

use App\Enums\RuleApplies;
use App\Models\Rule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rule>
 */
class RuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'priority' => 0,
            'active' => true,
            'match_contains' => fake()->word(),
            'match_not_contains' => null,
            'applies_to' => RuleApplies::Both,
            'set_payee' => null,
            'set_memo' => null,
            'category_id' => null,
        ];
    }
}
