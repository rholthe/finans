<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TransactionSearchTest extends TestCase
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

    public function test_fritekst_matcher_paa_tvers_av_kontoer(): void
    {
        $a = $this->budgetAccount();
        $b = $this->budgetAccount();

        $a->transactions()->create(['date' => '2026-01-10', 'amount' => -200, 'payee' => 'Rema 1000']);
        $b->transactions()->create(['date' => '2026-01-11', 'amount' => -50, 'memo' => 'rema kveld']);
        $a->transactions()->create(['date' => '2026-01-12', 'amount' => -99, 'payee' => 'Kiwi']);

        $response = $this->getJson('/api/transactions/search?q=rema')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $payees = collect($response->json('data'))->pluck('payee');
        $this->assertTrue($payees->contains('Rema 1000'));
        $this->assertFalse($payees->contains('Kiwi'));
    }

    public function test_dato_og_belopsfilter_avgrenser(): void
    {
        $a = $this->budgetAccount();
        $a->transactions()->create(['date' => '2026-01-05', 'amount' => -1000, 'payee' => 'A']);
        $a->transactions()->create(['date' => '2026-02-05', 'amount' => -100, 'payee' => 'B']);
        $a->transactions()->create(['date' => '2026-03-05', 'amount' => 5000, 'payee' => 'C']);

        $this->getJson('/api/transactions/search?from=2026-02-01&to=2026-02-28')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.payee', 'B');

        // Beløpsintervall (signert): kun store negative.
        $this->getJson('/api/transactions/search?min_amount=-1500&max_amount=-500')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.payee', 'A');
    }

    public function test_konto_og_ukategorisert_filter(): void
    {
        $a = $this->budgetAccount();
        $b = $this->budgetAccount();
        $category = Category::factory()->create();

        $a->transactions()->create(['date' => '2026-01-10', 'amount' => -200]); // ukategorisert
        $a->transactions()->create(['date' => '2026-01-11', 'amount' => -50, 'category_id' => $category->id]);
        $b->transactions()->create(['date' => '2026-01-12', 'amount' => -75]);

        $this->getJson("/api/transactions/search?account_id={$a->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson("/api/transactions/search?account_id={$a->id}&uncategorized=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.amount', -200);
    }

    public function test_needs_categorization_flagg_samsvarer_med_ukategorisert_filter(): void
    {
        $account = $this->budgetAccount();

        // Ekte ukategorisert (teller mot filteret → amber-merke i UI).
        $account->transactions()->create(['date' => '2026-01-10', 'amount' => -200, 'payee' => 'Rema']);
        // Reservert post uten kategori: vises i lista, men trenger ikke kategori ennå.
        $account->transactions()->create(['date' => '2026-01-11', 'amount' => -75, 'payee' => 'Kiwi', 'pending' => true]);

        $all = $this->getJson("/api/transactions/search?account_id={$account->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->json('data');

        $byPayee = collect($all)->keyBy('payee');
        $this->assertTrue($byPayee['Rema']['needs_categorization']);
        $this->assertFalse($byPayee['Kiwi']['needs_categorization']);

        // Filteret returnerer nøyaktig raden som er flagget needs_categorization.
        $this->getJson("/api/transactions/search?account_id={$account->id}&uncategorized=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.payee', 'Rema');
    }

    public function test_resultat_eksponerer_konto_og_kategorinavn(): void
    {
        $account = Account::factory()->create(['name' => 'Brukskonto', 'on_budget' => true]);
        $category = Category::factory()->create(['name' => 'Dagligvarer']);
        $account->transactions()->create([
            'date' => '2026-01-10',
            'amount' => -200,
            'payee' => 'Rema',
            'category_id' => $category->id,
        ]);

        $this->getJson('/api/transactions/search?q=rema')
            ->assertOk()
            ->assertJsonPath('data.0.account', 'Brukskonto')
            ->assertJsonPath('data.0.category', 'Dagligvarer');
    }

    public function test_per_konto_endepunkt_eksponerer_ikke_konto_kategorinavn(): void
    {
        $account = Account::factory()->create(['on_budget' => true]);
        $category = Category::factory()->create();
        $account->transactions()->create(['date' => '2026-01-10', 'amount' => -200, 'category_id' => $category->id]);

        $response = $this->getJson("/api/accounts/{$account->id}/transactions")->assertOk();

        $this->assertArrayNotHasKey('account', $response->json('data.0'));
        $this->assertArrayNotHasKey('category', $response->json('data.0'));
    }

    public function test_paginering(): void
    {
        $account = $this->budgetAccount();
        for ($i = 0; $i < 5; $i++) {
            $account->transactions()->create(['date' => '2026-01-'.(10 + $i), 'amount' => -10]);
        }

        $this->getJson('/api/transactions/search?per_page=2&page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonCount(2, 'data');
    }
}
