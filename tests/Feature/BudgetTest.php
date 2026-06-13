<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw']);
    }

    private function category(): Category
    {
        return Category::factory()->create();
    }

    private function budgetAccount(): Account
    {
        return Account::factory()->create(['on_budget' => true]);
    }

    public function test_tildeling_lagres_via_endepunkt(): void
    {
        $category = $this->category();

        $this->putJson("/api/budget/2026-01/categories/{$category->id}", ['assigned' => 1000])
            ->assertOk();

        $this->assertDatabaseHas('budget_allocations', [
            'category_id' => $category->id,
            'month' => '2026-01-01',
            'assigned' => 1000,
        ]);
    }

    public function test_ubrukt_tildeling_ruller_over_til_neste_maaned(): void
    {
        $category = $this->category();
        $this->putJson("/api/budget/2026-01/categories/{$category->id}", ['assigned' => 1000]);

        // Februar: ingen ny tildeling, ingen aktivitet → available = 1000 fra januar
        $this->getJson('/api/budget?month=2026-02')
            ->assertOk()
            ->assertJsonPath('groups.0.categories.0.assigned', 0)
            ->assertJsonPath('groups.0.categories.0.activity', 0)
            ->assertJsonPath('groups.0.categories.0.available', 1000);
    }

    public function test_aktivitet_og_tilgjengelig_innenfor_maaneden(): void
    {
        $category = $this->category();
        $account = $this->budgetAccount();
        $this->putJson("/api/budget/2026-01/categories/{$category->id}", ['assigned' => 1000]);

        $account->transactions()->create([
            'category_id' => $category->id,
            'date' => '2026-01-15',
            'amount' => -300,
        ]);

        $this->getJson('/api/budget?month=2026-01')
            ->assertOk()
            ->assertJsonPath('groups.0.categories.0.assigned', 1000)
            ->assertJsonPath('groups.0.categories.0.activity', -300)
            ->assertJsonPath('groups.0.categories.0.available', 700);

        // Resten (700) ruller over til februar
        $this->getJson('/api/budget?month=2026-02')
            ->assertJsonPath('groups.0.categories.0.available', 700);
    }

    public function test_ready_to_assign_er_penger_minus_tildelt(): void
    {
        $category = $this->category();
        $account = $this->budgetAccount();

        // Inntekt (ukategorisert) = 5000 inn på budsjettkonto
        $account->transactions()->create([
            'date' => '2026-01-01',
            'amount' => 5000,
            'is_starting_balance' => true,
        ]);
        $this->putJson("/api/budget/2026-01/categories/{$category->id}", ['assigned' => 1000]);

        $this->getJson('/api/budget?month=2026-01')
            ->assertOk()
            ->assertJsonPath('ready_to_assign', 4000);
    }

    public function test_overvaaket_konto_paavirker_ikke_budsjett(): void
    {
        $category = $this->category();
        $tracking = Account::factory()->tracking()->create();

        $tracking->transactions()->create([
            'category_id' => $category->id,
            'date' => '2026-01-10',
            'amount' => -999,
        ]);

        $this->getJson('/api/budget?month=2026-01')
            ->assertOk()
            ->assertJsonPath('groups.0.categories.0.activity', 0)
            ->assertJsonPath('ready_to_assign', 0);
    }

    public function test_overforbruk_ruller_over_som_negativt(): void
    {
        $category = $this->category();
        $account = $this->budgetAccount();
        $this->putJson("/api/budget/2026-01/categories/{$category->id}", ['assigned' => 100]);

        $account->transactions()->create([
            'category_id' => $category->id,
            'date' => '2026-01-20',
            'amount' => -250,
        ]);

        // Januar: 100 - 250 = -150
        $this->getJson('/api/budget?month=2026-01')
            ->assertJsonPath('groups.0.categories.0.available', -150);

        // Februar: -150 ruller over
        $this->getJson('/api/budget?month=2026-02')
            ->assertJsonPath('groups.0.categories.0.available', -150);
    }

    public function test_redigering_av_gammel_transaksjon_oppdaterer_fremtidig_tilgjengelig(): void
    {
        $category = $this->category();
        $account = $this->budgetAccount();
        $this->putJson("/api/budget/2026-01/categories/{$category->id}", ['assigned' => 1000]);

        $transaction = $account->transactions()->create([
            'category_id' => $category->id,
            'date' => '2026-01-15',
            'amount' => -300,
        ]);

        $this->getJson('/api/budget?month=2026-02')
            ->assertJsonPath('groups.0.categories.0.available', 700);

        // Endre fortidens transaksjon → fremtidig available korrigeres automatisk
        $this->patchJson("/api/transactions/{$transaction->id}", ['amount' => -500]);

        $this->getJson('/api/budget?month=2026-02')
            ->assertJsonPath('groups.0.categories.0.available', 500);
    }
}
