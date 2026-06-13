<?php

namespace Database\Factories;

use App\Models\BudgetAllocation;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetAllocation>
 */
class BudgetAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'month' => now()->startOfMonth()->toDateString(),
            'assigned' => fake()->randomFloat(2, 0, 5000),
        ];
    }
}
