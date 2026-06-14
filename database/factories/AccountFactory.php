<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'type' => fake()->randomElement(AccountType::cases()),
            'on_budget' => true,
            'currency' => 'NOK',
            'closed' => false,
            'note' => null,
        ];
    }

    public function tracking(): static
    {
        return $this->state(fn (): array => ['on_budget' => false]);
    }

    public function credit(): static
    {
        return $this->state(fn (): array => ['type' => AccountType::Credit, 'on_budget' => true]);
    }
}
