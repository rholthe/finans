<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw']);
    }

    public function test_oppretter_transaksjon_og_paavirker_saldo(): void
    {
        $account = Account::factory()->create();

        $this->postJson("/api/accounts/{$account->id}/transactions", [
            'date' => '2026-06-01',
            'amount' => -349.90,
            'payee' => 'Rema 1000',
            'memo' => 'Mat',
        ])
            ->assertCreated()
            ->assertJsonPath('data.amount', -349.9)
            ->assertJsonPath('data.payee', 'Rema 1000');

        $this->getJson("/api/accounts/{$account->id}")
            ->assertJsonPath('data.balance', -349.9);
    }

    public function test_lister_transaksjoner_nyeste_forst(): void
    {
        $account = Account::factory()->create();
        Transaction::factory()->for($account)->create(['date' => '2026-01-01', 'payee' => 'Eldst']);
        Transaction::factory()->for($account)->create(['date' => '2026-06-01', 'payee' => 'Nyest']);

        $this->getJson("/api/accounts/{$account->id}/transactions")
            ->assertOk()
            ->assertJsonPath('data.0.payee', 'Nyest')
            ->assertJsonPath('data.1.payee', 'Eldst');
    }

    public function test_filtrerer_paa_dato_og_sidestorrelse(): void
    {
        $account = Account::factory()->create();
        Transaction::factory()->for($account)->create(['date' => '2026-01-15']);
        Transaction::factory()->for($account)->create(['date' => '2026-03-15']);
        Transaction::factory()->for($account)->create(['date' => '2026-06-15']);

        $this->getJson("/api/accounts/{$account->id}/transactions?from=2026-02-01&to=2026-05-01")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', '2026-03-15');

        $this->getJson("/api/accounts/{$account->id}/transactions?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_oppdaterer_transaksjon(): void
    {
        $tx = Transaction::factory()->create(['payee' => 'Feil', 'cleared' => false]);

        $this->patchJson("/api/transactions/{$tx->id}", [
            'payee' => 'Riktig',
            'cleared' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.payee', 'Riktig')
            ->assertJsonPath('data.cleared', true);
    }

    public function test_inline_kategorisering_setter_kategori_rta_og_ukategorisert(): void
    {
        $account = Account::factory()->create(['on_budget' => true]);
        $category = Category::factory()->create();
        $tx = Transaction::factory()->for($account)->create([
            'category_id' => null,
            'rta' => false,
            'amount' => -200,
        ]);

        // Sett en konkret kategori (nullstiller rta).
        $this->patchJson("/api/transactions/{$tx->id}", ['category_id' => $category->id, 'rta' => false])
            ->assertOk()
            ->assertJsonPath('data.category_id', $category->id)
            ->assertJsonPath('data.rta', false);

        // Sett «Klar til å fordele» (RTA) – kategori nullstilles.
        $this->patchJson("/api/transactions/{$tx->id}", ['category_id' => null, 'rta' => true])
            ->assertOk()
            ->assertJsonPath('data.category_id', null)
            ->assertJsonPath('data.rta', true);

        // Tilbake til ukategorisert.
        $this->patchJson("/api/transactions/{$tx->id}", ['category_id' => null, 'rta' => false])
            ->assertOk()
            ->assertJsonPath('data.category_id', null)
            ->assertJsonPath('data.rta', false);
    }

    public function test_sletter_transaksjon(): void
    {
        $tx = Transaction::factory()->create();

        $this->deleteJson("/api/transactions/{$tx->id}")->assertNoContent();

        $this->assertDatabaseMissing('transactions', ['id' => $tx->id]);
    }

    public function test_krever_belop_og_dato(): void
    {
        $account = Account::factory()->create();

        $this->postJson("/api/accounts/{$account->id}/transactions", ['payee' => 'X'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date', 'amount']);
    }
}
