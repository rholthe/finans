<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw']);
    }

    public function test_overforing_oppretter_to_sammenkoblede_ben(): void
    {
        $from = Account::factory()->create(['name' => 'Brukskonto']);
        $to = Account::factory()->create(['name' => 'Sparekonto']);

        $this->postJson('/api/transfers', [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 500,
            'date' => '2026-01-10',
        ])->assertCreated();

        $fromLeg = $from->transactions()->firstOrFail();
        $toLeg = $to->transactions()->firstOrFail();

        $this->assertEquals(-500, $fromLeg->amount);
        $this->assertEquals(500, $toLeg->amount);
        $this->assertSame($toLeg->id, $fromLeg->transfer_id);
        $this->assertSame($fromLeg->id, $toLeg->transfer_id);
        $this->assertSame('Overføring til Sparekonto', $fromLeg->payee);
        $this->assertSame('Overføring fra Brukskonto', $toLeg->payee);
    }

    public function test_overforing_til_egen_konto_avvises(): void
    {
        $account = Account::factory()->create();

        $this->postJson('/api/transfers', [
            'from_account_id' => $account->id,
            'to_account_id' => $account->id,
            'amount' => 100,
            'date' => '2026-01-10',
        ])->assertStatus(422);
    }

    public function test_sletting_av_ett_ben_fjerner_begge(): void
    {
        $from = Account::factory()->create();
        $to = Account::factory()->create();

        $this->postJson('/api/transfers', [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 250,
            'date' => '2026-01-10',
        ])->assertCreated();

        $fromLeg = $from->transactions()->firstOrFail();
        $this->deleteJson("/api/transactions/{$fromLeg->id}")->assertNoContent();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_overforing_kan_ikke_redigeres(): void
    {
        $from = Account::factory()->create();
        $to = Account::factory()->create();

        $this->postJson('/api/transfers', [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 250,
            'date' => '2026-01-10',
        ])->assertCreated();

        $leg = $from->transactions()->firstOrFail();
        $this->patchJson("/api/transactions/{$leg->id}", ['amount' => -999])->assertStatus(422);
        $this->assertEquals(-250, $leg->fresh()->amount);
    }

    public function test_overforing_paavirker_ikke_ready_to_assign(): void
    {
        $checking = Account::factory()->create(['type' => 'bank', 'on_budget' => true]);
        $savings = Account::factory()->create(['type' => 'bank', 'on_budget' => true]);
        $checking->transactions()->create(['date' => '2026-01-01', 'amount' => 5000, 'is_starting_balance' => true]);

        $this->getJson('/api/budget?month=2026-01')->assertJsonPath('ready_to_assign', 5000);

        $this->postJson('/api/transfers', [
            'from_account_id' => $checking->id,
            'to_account_id' => $savings->id,
            'amount' => 1000,
            'date' => '2026-01-05',
        ])->assertCreated();

        // Penger flyttes mellom to budsjettkontoer – RTA er uendret.
        $this->getJson('/api/budget?month=2026-01')->assertJsonPath('ready_to_assign', 5000);
    }

    public function test_overforing_betaler_ned_kredittkort(): void
    {
        $mat = Category::factory()->create();
        $checking = Account::factory()->create(['type' => 'bank', 'on_budget' => true]);
        $checking->transactions()->create(['date' => '2026-01-01', 'amount' => 5000, 'is_starting_balance' => true]);
        $this->putJson("/api/budget/2026-01/categories/{$mat->id}", ['assigned' => 1000]);

        $visa = Account::factory()->credit()->create(['name' => 'Visa']);
        $visa->transactions()->create(['category_id' => $mat->id, 'date' => '2026-01-15', 'amount' => -300]);

        // Kredittkjøpet rører ikke RTA (kategorisert forbruk).
        $this->getJson('/api/budget?month=2026-01')->assertJsonPath('ready_to_assign', 4000);

        // Betal kortet: overfør 300 fra brukskonto til Visa.
        $this->postJson('/api/transfers', [
            'from_account_id' => $checking->id,
            'to_account_id' => $visa->id,
            'amount' => 300,
            'date' => '2026-01-20',
        ])->assertCreated();

        // Visa er nedbetalt (saldo 0), brukskonto redusert tilsvarende, RTA uberørt.
        $this->assertEquals(0, $visa->transactions()->sum('amount'));
        $this->assertEquals(4700, $checking->transactions()->sum('amount'));
        $this->getJson('/api/budget?month=2026-01')->assertJsonPath('ready_to_assign', 4000);
    }
}
