<?php

namespace Tests\Feature;

use App\Enums\ScheduleFrequency;
use App\Models\Account;
use App\Models\Category;
use App\Models\ScheduledTransaction;
use App\Services\ScheduledTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ScheduledTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw']);
    }

    private function postDue(): int
    {
        return app(ScheduledTransactionService::class)->postDue();
    }

    // --- CRUD ---

    public function test_oppretter_planlagt_med_next_date_lik_startdato(): void
    {
        $account = Account::factory()->create();
        $start = now()->addMonths(2)->toDateString();

        $this->postJson('/api/scheduled-transactions', [
            'account_id' => $account->id,
            'amount' => -1000,
            'payee' => 'Husleie',
            'frequency' => 'monthly',
            'start_date' => $start,
        ])
            ->assertCreated()
            ->assertJsonPath('data.payee', 'Husleie')
            ->assertJsonPath('data.frequency', 'monthly')
            ->assertJsonPath('data.next_date', $start);
    }

    public function test_avviser_sluttdato_for_startdato(): void
    {
        $account = Account::factory()->create();

        $this->postJson('/api/scheduled-transactions', [
            'account_id' => $account->id,
            'amount' => -1000,
            'frequency' => 'monthly',
            'start_date' => now()->addMonths(2)->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_lister_og_sletter(): void
    {
        $scheduled = ScheduledTransaction::factory()->startingOn('2030-01-01')->create();

        $this->getJson('/api/scheduled-transactions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $scheduled->id);

        $this->deleteJson("/api/scheduled-transactions/{$scheduled->id}")->assertNoContent();
        $this->assertDatabaseMissing('scheduled_transactions', ['id' => $scheduled->id]);
    }

    // --- Postering ---

    public function test_posterer_forfalt_forekomst_og_avanserer(): void
    {
        $this->travelTo('2026-01-15');
        $account = Account::factory()->create();
        $schedule = ScheduledTransaction::factory()
            ->startingOn('2026-01-10')
            ->create(['account_id' => $account->id, 'amount' => -500]);

        $this->assertSame(1, $this->postDue());

        $transaction = $account->transactions()->where('scheduled_transaction_id', $schedule->id)->sole();
        $this->assertSame('2026-01-10', $transaction->date->toDateString());
        $this->assertSame(-500.0, (float) $transaction->amount);

        $schedule->refresh();
        $this->assertSame('2026-02-10', $schedule->next_date->toDateString());
        $this->assertSame('2026-01-10', $schedule->last_posted_date->toDateString());
    }

    public function test_tar_igjen_flere_bomte_forekomster(): void
    {
        $this->travelTo('2026-01-29');
        ScheduledTransaction::factory()
            ->frequency(ScheduleFrequency::Weekly)
            ->startingOn('2026-01-01')
            ->create();

        // 01-01, 01-08, 01-15, 01-22, 01-29 = 5 forekomster
        $this->assertSame(5, $this->postDue());
        $this->assertDatabaseCount('transactions', 5);
    }

    public function test_stopper_ved_sluttdato(): void
    {
        $this->travelTo('2026-03-01');
        ScheduledTransaction::factory()
            ->frequency(ScheduleFrequency::Weekly)
            ->startingOn('2026-01-01')
            ->create(['end_date' => '2026-01-15']);

        // 01-01, 01-08, 01-15 = 3, deretter stopp
        $this->assertSame(3, $this->postDue());
        $this->assertDatabaseCount('transactions', 3);
    }

    public function test_er_idempotent(): void
    {
        $this->travelTo('2026-01-15');
        ScheduledTransaction::factory()->startingOn('2026-01-10')->create(['amount' => -500]);

        $this->postDue();
        $this->assertSame(0, $this->postDue());
        $this->assertDatabaseCount('transactions', 1);
    }

    // --- Budsjett-projeksjon ---

    public function test_kommende_utgift_senker_projisert_tilgjengelig(): void
    {
        $this->travelTo('2026-01-05');
        $category = Category::factory()->create();
        $account = Account::factory()->create(['on_budget' => true]);

        $this->putJson("/api/budget/2026-01/categories/{$category->id}", ['assigned' => 1000]);

        ScheduledTransaction::factory()
            ->startingOn('2026-01-28')
            ->create(['account_id' => $account->id, 'category_id' => $category->id, 'amount' => -800]);

        $this->getJson('/api/budget?month=2026-01')
            ->assertOk()
            ->assertJsonPath('groups.0.categories.0.available', 1000)
            ->assertJsonPath('groups.0.categories.0.upcoming', -800)
            ->assertJsonPath('groups.0.categories.0.projected_available', 200);
    }

    public function test_kommende_inntekt_hever_projisert_ready_to_assign(): void
    {
        $this->travelTo('2026-01-05');
        $account = Account::factory()->create(['on_budget' => true]);

        ScheduledTransaction::factory()
            ->startingOn('2026-01-20')
            ->create(['account_id' => $account->id, 'category_id' => null, 'amount' => 3000]);

        $this->getJson('/api/budget?month=2026-01')
            ->assertOk()
            ->assertJsonPath('ready_to_assign', 0)
            ->assertJsonPath('upcoming_income', 3000)
            ->assertJsonPath('projected_ready_to_assign', 3000);
    }

    public function test_middleware_posterer_forfalt_ved_api_kall(): void
    {
        $this->travelTo('2026-01-15');
        $account = Account::factory()->create(['on_budget' => true]);
        ScheduledTransaction::factory()
            ->startingOn('2026-01-10')
            ->create(['account_id' => $account->id, 'amount' => -500]);

        // Et hvilket som helst beskyttet kall skal trigge postering først
        $this->getJson('/api/accounts')->assertOk();

        $this->assertDatabaseCount('transactions', 1);
    }
}
