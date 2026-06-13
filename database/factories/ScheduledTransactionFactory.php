<?php

namespace Database\Factories;

use App\Enums\ScheduleFrequency;
use App\Models\Account;
use App\Models\ScheduledTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledTransaction>
 */
class ScheduledTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->startOfMonth()->toDateString();

        return [
            'account_id' => Account::factory(),
            'category_id' => null,
            'amount' => -fake()->randomFloat(2, 100, 3000),
            'payee' => fake()->company(),
            'memo' => null,
            'frequency' => ScheduleFrequency::Monthly,
            'start_date' => $start,
            'next_date' => $start,
            'end_date' => null,
            'last_posted_date' => null,
        ];
    }

    public function frequency(ScheduleFrequency $frequency): static
    {
        return $this->state(fn (): array => ['frequency' => $frequency]);
    }

    public function startingOn(string $date): static
    {
        return $this->state(fn (): array => ['start_date' => $date, 'next_date' => $date]);
    }
}
