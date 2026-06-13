<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\CategoryGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_group_id' => CategoryGroup::factory(),
            'name' => fake()->unique()->words(2, true),
            'sort_order' => 0,
            'note' => null,
        ];
    }
}
