<?php

namespace Database\Factories;

use App\Enums\GoalType;
use App\Models\Category;
use App\Models\Goal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'type' => GoalType::Monthly,
            'target_amount' => fake()->randomFloat(2, 100, 5000),
            'target_date' => null,
        ];
    }

    public function monthly(float $amount): static
    {
        return $this->state(fn (): array => [
            'type' => GoalType::Monthly,
            'target_amount' => $amount,
            'target_date' => null,
        ]);
    }

    public function targetBalance(float $amount): static
    {
        return $this->state(fn (): array => [
            'type' => GoalType::TargetBalance,
            'target_amount' => $amount,
            'target_date' => null,
        ]);
    }

    public function targetBalanceByDate(float $amount, string $date): static
    {
        return $this->state(fn (): array => [
            'type' => GoalType::TargetBalanceByDate,
            'target_amount' => $amount,
            'target_date' => $date,
        ]);
    }
}
