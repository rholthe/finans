<?php

namespace Tests\Feature;

use App\Models\Account;
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

    public function test_lister_etter_prioritet(): void
    {
        Rule::factory()->create(['priority' => 5, 'set_payee' => 'B']);
        Rule::factory()->create(['priority' => 1, 'set_payee' => 'A']);

        $this->getJson('/api/rules')
            ->assertOk()
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

    public function test_endrer_rekkefolge(): void
    {
        $a = Rule::factory()->create(['priority' => 1]);
        $b = Rule::factory()->create(['priority' => 2]);

        $this->putJson('/api/rules/reorder', [
            'rules' => [
                ['id' => $a->id, 'priority' => 10],
                ['id' => $b->id, 'priority' => 5],
            ],
        ])->assertNoContent();

        $this->assertDatabaseHas('rules', ['id' => $a->id, 'priority' => 10]);
        $this->assertDatabaseHas('rules', ['id' => $b->id, 'priority' => 5]);
    }

    public function test_reapply_oppdaterer_eksisterende_banktransaksjon(): void
    {
        $account = Account::factory()->create();
        $category = Category::factory()->create();
        $transaction = Transaction::create([
            'account_id' => $account->id,
            'external_id' => 'tx-1',
            'bank_description' => 'KORTKJØP REMA 1000 OSLO',
            'date' => '2026-01-10',
            'amount' => -250,
            'payee' => 'KORTKJØP REMA 1000 OSLO',
        ]);
        $rule = Rule::factory()->create([
            'match_contains' => 'REMA',
            'set_payee' => 'Rema 1000',
            'category_id' => $category->id,
        ]);

        $this->postJson('/api/rules/reapply')
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $transaction->refresh();
        $this->assertSame('Rema 1000', $transaction->payee);
        $this->assertSame($category->id, $transaction->category_id);
        $this->assertSame($rule->id, $transaction->rule_id);
    }

    public function test_reapply_rorer_ikke_manuelle_transaksjoner(): void
    {
        $account = Account::factory()->create();
        // Manuell transaksjon uten bank_description skal ikke berøres.
        $manual = Transaction::create([
            'account_id' => $account->id,
            'date' => '2026-01-10',
            'amount' => -100,
            'payee' => 'Manuell',
        ]);
        Rule::factory()->create(['match_contains' => 'Manuell', 'set_payee' => 'Endret']);

        $this->postJson('/api/rules/reapply')->assertJsonPath('updated', 0);

        $this->assertSame('Manuell', $manual->fresh()->payee);
    }
}
