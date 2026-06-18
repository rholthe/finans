<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw']);
    }

    public function test_krever_innlogging(): void
    {
        $this->flushSession();

        $this->getJson('/api/accounts')->assertStatus(401);
    }

    public function test_lister_kontoer_med_saldo(): void
    {
        $account = Account::factory()->create(['name' => 'Brukskonto']);
        $account->transactions()->createMany([
            ['date' => now(), 'amount' => 1000],
            ['date' => now(), 'amount' => -250.50],
        ]);

        $this->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Brukskonto')
            ->assertJsonPath('data.0.balance', 749.5);
    }

    public function test_oppretter_konto(): void
    {
        $this->postJson('/api/accounts', [
            'name' => 'Sparekonto',
            'type' => 'bank',
            'on_budget' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Sparekonto')
            ->assertJsonPath('data.type', 'bank')
            ->assertJsonPath('data.balance', 0)
            ->assertJsonPath('data.closed', false);

        $this->assertDatabaseHas('accounts', ['name' => 'Sparekonto', 'type' => 'bank']);
    }

    public function test_oppretter_sparekonto(): void
    {
        $this->postJson('/api/accounts', [
            'name' => 'BSU',
            'type' => 'saving',
            'on_budget' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'saving');

        $this->assertDatabaseHas('accounts', ['name' => 'BSU', 'type' => 'saving']);
    }

    public function test_startsaldo_blir_egen_transaksjon(): void
    {
        $response = $this->postJson('/api/accounts', [
            'name' => 'Lommebok',
            'type' => 'cash',
            'starting_balance' => 500,
        ])->assertCreated()->assertJsonPath('data.balance', 500);

        $this->assertDatabaseHas('transactions', [
            'account_id' => $response->json('data.id'),
            'amount' => 500,
            'is_starting_balance' => true,
        ]);
    }

    public function test_oppdaterer_konto(): void
    {
        $account = Account::factory()->create(['name' => 'Gammel', 'on_budget' => true]);

        $this->patchJson("/api/accounts/{$account->id}", [
            'name' => 'Ny',
            'on_budget' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ny')
            ->assertJsonPath('data.on_budget', false);
    }

    public function test_sletting_fjerner_transaksjoner(): void
    {
        $account = Account::factory()->create();
        $account->transactions()->create(['date' => now(), 'amount' => 100]);

        $this->deleteJson("/api/accounts/{$account->id}")->assertNoContent();

        $this->assertDatabaseMissing('accounts', ['id' => $account->id]);
        $this->assertDatabaseMissing('transactions', ['account_id' => $account->id]);
    }

    public function test_avviser_ugyldig_kontotype(): void
    {
        $this->postJson('/api/accounts', ['name' => 'X', 'type' => 'crypto'])
            ->assertStatus(422);
    }
}
