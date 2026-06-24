<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw']);
    }

    private function budgetAccount(): Account
    {
        return Account::factory()->create(['on_budget' => true]);
    }

    public function test_avstemming_uten_avvik_stempler_klarerte_uten_justering(): void
    {
        $account = $this->budgetAccount();
        $cleared = $account->transactions()->create([
            'date' => '2026-01-10',
            'amount' => 1000,
            'cleared' => true,
        ]);
        $uncleared = $account->transactions()->create([
            'date' => '2026-01-11',
            'amount' => -200,
            'cleared' => false,
        ]);

        $this->postJson("/api/accounts/{$account->id}/reconcile", ['statement_balance' => 1000])
            ->assertOk()
            ->assertJsonPath('adjustment_amount', 0)
            ->assertJsonPath('cleared_balance', 1000);

        // Ingen justeringstransaksjon opprettet.
        $this->assertEquals(2, $account->transactions()->count());
        // Klarert rad er stemplet, uklarert er ikke.
        $this->assertNotNull($cleared->fresh()->reconciled_at);
        $this->assertNull($uncleared->fresh()->reconciled_at);

        $this->assertDatabaseHas('reconciliations', [
            'account_id' => $account->id,
            'statement_balance' => 1000,
            'adjustment_amount' => 0,
        ]);
    }

    public function test_positivt_avvik_lager_ukategorisert_justering_og_oeker_rta(): void
    {
        $account = $this->budgetAccount();
        $account->transactions()->create([
            'date' => '2026-01-10',
            'amount' => 1000,
            'rta' => true,
            'cleared' => true,
        ]);

        // Faktisk saldo er 1200 → +200 justering.
        $this->postJson("/api/accounts/{$account->id}/reconcile", [
            'statement_balance' => 1200,
            'date' => '2026-01-15',
        ])
            ->assertOk()
            ->assertJsonPath('adjustment_amount', 200)
            ->assertJsonPath('account.cleared_balance', 1200)
            ->assertJsonPath('account.balance', 1200);

        $adjustment = $account->transactions()->where('payee', 'Avstemmingsjustering')->first();
        $this->assertNotNull($adjustment);
        $this->assertEquals(200, $adjustment->amount);
        $this->assertNull($adjustment->category_id);
        $this->assertTrue($adjustment->cleared);
        $this->assertNotNull($adjustment->reconciled_at);

        // Ukategorisert justering på budsjettkonto øker Ready to Assign.
        $this->getJson('/api/budget?month=2026-01')
            ->assertJsonPath('ready_to_assign', 1200);
    }

    public function test_negativt_avvik_reduserer_rta(): void
    {
        $account = $this->budgetAccount();
        $account->transactions()->create([
            'date' => '2026-01-10',
            'amount' => 1000,
            'rta' => true,
            'cleared' => true,
        ]);

        $this->postJson("/api/accounts/{$account->id}/reconcile", [
            'statement_balance' => 800,
            'date' => '2026-01-15',
        ])
            ->assertOk()
            ->assertJsonPath('adjustment_amount', -200);

        $this->getJson('/api/budget?month=2026-01')
            ->assertJsonPath('ready_to_assign', 800);
    }

    public function test_avstemming_av_konto_utenfor_budsjett_paavirker_ikke_rta(): void
    {
        $tracking = Account::factory()->tracking()->create();
        $tracking->transactions()->create([
            'date' => '2026-01-10',
            'amount' => 5000,
            'cleared' => true,
        ]);

        $this->postJson("/api/accounts/{$tracking->id}/reconcile", ['statement_balance' => 5500])
            ->assertOk()
            ->assertJsonPath('adjustment_amount', 500)
            ->assertJsonPath('account.balance', 5500);

        // Justering på konto utenfor budsjett endrer ikke Ready to Assign.
        $this->getJson('/api/budget?month=2026-01')
            ->assertJsonPath('ready_to_assign', 0);
    }

    public function test_klarert_saldo_eksponeres_paa_konto(): void
    {
        $account = $this->budgetAccount();
        $account->transactions()->create(['date' => '2026-01-10', 'amount' => 1000, 'cleared' => true]);
        $account->transactions()->create(['date' => '2026-01-11', 'amount' => -300, 'cleared' => false]);

        $this->getJson("/api/accounts/{$account->id}")
            ->assertOk()
            ->assertJsonPath('data.balance', 700)
            ->assertJsonPath('data.cleared_balance', 1000);

        $this->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.cleared_balance', 1000);
    }
}
