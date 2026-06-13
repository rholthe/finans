<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'date' => fake()->dateTimeBetween('-2 months', 'now')->format('Y-m-d'),
            'amount' => fake()->randomFloat(2, -5000, 5000),
            'payee' => fake()->company(),
            'memo' => null,
            'cleared' => fake()->boolean(),
            'is_starting_balance' => false,
        ];
    }
}
