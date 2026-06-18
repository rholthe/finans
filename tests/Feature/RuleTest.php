<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankConnection;
use App\Models\Category;
use App\Models\Rule;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw']);
    }

    public function test_oppretter_regel(): void
    {
        $this->postJson('/api/rules', [
            'match_contains' => 'REMA',
            'set_payee' => 'Rema 1000',
        ])
            ->assertCreated()
            ->assertJsonPath('data.set_payee', 'Rema 1000')
            ->assertJsonPath('data.applies_to', 'both');

        $this->assertDatabaseHas('rules', ['set_payee' => 'Rema 1000']);
    }

    public function test_avviser_regel_uten_handling(): void
    {
        $this->postJson('/api/rules', ['match_contains' => 'REMA'])
            ->assertStatus(422);
    }

    public function test_lister_regler(): void
    {
        Rule::factory()->create(['set_payee' => 'A']);
        Rule::factory()->create(['set_payee' => 'B']);

        $this->getJson('/api/rules')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.set_payee', 'A')
            ->assertJsonPath('data.1.set_payee', 'B');
    }

    public function test_oppdaterer_og_sletter(): void
    {
        $rule = Rule::factory()->create(['set_payee' => 'Gammel']);

        $this->patchJson("/api/rules/{$rule->id}", ['set_payee' => 'Ny'])
            ->assertOk()
            ->assertJsonPath('data.set_payee', 'Ny');

        $this->deleteJson("/api/rules/{$rule->id}")->assertNoContent();
        $this->assertDatabaseMissing('rules', ['id' => $rule->id]);
    }

    private function bankTransaction(array $attributes = []): Transaction
    {
        $account = Account::factory()->create();

        return Transaction::create(array_merge([
            'account_id' => $account->id,
            'external_id' => 'tx-'.fake()->unique()->numerify('####'),
            'bank_description' => 'KORTKJØP REMA 1000 OSLO',
            'date' => '2026-01-10',
            'amount' => -250,
            'payee' => 'KORTKJØP REMA 1000 OSLO',
        ], $attributes));
    }

    public function test_apply_rules_paa_avgrenset_sett(): void
    {
        $category = Category::factory()->create();
        $transaction = $this->bankTransaction();
        $rule = Rule::factory()->create([
            'match_contains' => 'REMA',
            'set_payee' => 'Rema 1000',
            'category_id' => $category->id,
        ]);

        $this->postJson('/api/transactions/apply-rules', ['transaction_ids' => [$transaction->id]])
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $transaction->refresh();
        $this->assertSame('Rema 1000', $transaction->payee);
        $this->assertSame($category->id, $transaction->category_id);
        $this->assertSame($rule->id, $transaction->rule_id);
    }

    public function test_apply_rules_hopper_over_laaste(): void
    {
        $transaction = $this->bankTransaction(['locked' => true]);
        Rule::factory()->create(['match_contains' => 'REMA', 'set_payee' => 'Rema 1000']);

        $this->postJson('/api/transactions/apply-rules', ['transaction_ids' => [$transaction->id]])
            ->assertJsonPath('updated', 0);

        $this->assertSame('KORTKJØP REMA 1000 OSLO', $transaction->fresh()->payee);
    }

    public function test_apply_rules_hopper_over_allerede_matchede(): void
    {
        $rule = Rule::factory()->create(['match_contains' => 'REMA', 'set_payee' => 'Rema 1000']);
        $transaction = $this->bankTransaction(['rule_id' => $rule->id, 'payee' => 'Rema 1000']);

        // Allerede matchet (rule_id satt) → hoppes alltid over.
        $this->postJson('/api/transactions/apply-rules', ['transaction_ids' => [$transaction->id]])
            ->assertJsonPath('updated', 0);
    }

    public function test_manuell_redigering_laaser_transaksjonen(): void
    {
        $transaction = $this->bankTransaction();

        $this->patchJson("/api/transactions/{$transaction->id}", ['payee' => 'Min payee'])
            ->assertOk()
            ->assertJsonPath('data.locked', true);

        $this->assertTrue($transaction->fresh()->locked);
    }

    public function test_rta_regel_er_gyldig_uten_payee_memo_kategori(): void
    {
        $this->postJson('/api/rules', [
            'match_contains' => 'LØNN',
            'target_type' => 'rta',
        ])
            ->assertCreated()
            ->assertJsonPath('data.target_type', 'rta');
    }

    public function test_overforingsregel_krever_mottakerkonto(): void
    {
        $this->postJson('/api/rules', [
            'match_contains' => 'SPARING',
            'target_type' => 'transfer',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('transfer_account_id');
    }

    public function test_overforingsregel_avviser_synket_malkonto(): void
    {
        $account = Account::factory()->create();
        // Koble en bank-konto til kontoen → den er nå «synket».
        $connection = BankConnection::create([
            'institution_id' => 'DNB', 'name' => 'DNB', 'consent_id' => 'c1', 'status' => 'LN',
        ]);
        $connection->bankAccounts()->create(['account_id' => $account->id, 'external_id' => 'acc-x']);

        $this->postJson('/api/rules', [
            'match_contains' => 'SPARING',
            'target_type' => 'transfer',
            'transfer_account_id' => $account->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('transfer_account_id');
    }
}
