<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Reconciliation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reconciliation>
 */
class ReconciliationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $balance = fake()->randomFloat(2, 0, 50000);

        return [
            'account_id' => Account::factory(),
            'reconciled_at' => now(),
            'statement_balance' => $balance,
            'cleared_balance' => $balance,
            'adjustment_amount' => 0,
        ];
    }
}
