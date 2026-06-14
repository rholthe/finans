<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReportTest extends TestCase
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

    public function test_forbruk_per_kategori_summerer_og_ekskluderer_inntekt(): void
    {
        $group = CategoryGroup::factory()->create(['name' => 'Mat']);
        $mat = Category::factory()->create(['category_group_id' => $group->id, 'name' => 'Dagligvarer']);
        $account = $this->budgetAccount();

        $account->transactions()->create(['category_id' => $mat->id, 'date' => '2026-01-10', 'amount' => -300]);
        $account->transactions()->create(['category_id' => $mat->id, 'date' => '2026-02-10', 'amount' => -200]);
        // Inntekt (ukategorisert) skal ikke telle som forbruk.
        $account->transactions()->create(['date' => '2026-01-01', 'amount' => 5000]);
        // Utenfor perioden.
        $account->transactions()->create(['category_id' => $mat->id, 'date' => '2026-05-10', 'amount' => -999]);

        $this->getJson('/api/reports/spending?from=2026-01&to=2026-03')
            ->assertOk()
            ->assertJsonPath('total', 500)
            ->assertJsonPath('groups.0.name', 'Mat')
            ->assertJsonPath('groups.0.total', 500)
            ->assertJsonPath('groups.0.categories.0.name', 'Dagligvarer')
            ->assertJsonPath('groups.0.categories.0.total', 500);
    }

    public function test_inntekt_vs_forbruk_skiller_og_hopper_over_overforing(): void
    {
        $category = Category::factory()->create();
        $account = $this->budgetAccount();
        $other = $this->budgetAccount();

        $account->transactions()->create(['date' => '2026-01-05', 'amount' => 4000]); // inntekt
        $account->transactions()->create(['category_id' => $category->id, 'date' => '2026-01-20', 'amount' => -1500]); // forbruk
        // Overføring (ukategorisert, men transfer_id satt) skal ikke telle som inntekt.
        $this->postJson('/api/transfers', [
            'from_account_id' => $other->id,
            'to_account_id' => $account->id,
            'amount' => 1000,
            'date' => '2026-01-15',
        ])->assertCreated();

        $this->getJson('/api/reports/income-expense?from=2026-01&to=2026-01')
            ->assertOk()
            ->assertJsonPath('months.0.month', '2026-01')
            ->assertJsonPath('months.0.income', 4000)
            ->assertJsonPath('months.0.expense', 1500)
            ->assertJsonPath('months.0.net', 2500);
    }

    public function test_kategoritrend_gir_maanedlig_serie(): void
    {
        $category = Category::factory()->create();
        $account = $this->budgetAccount();

        $account->transactions()->create(['category_id' => $category->id, 'date' => '2026-01-10', 'amount' => -300]);
        $account->transactions()->create(['category_id' => $category->id, 'date' => '2026-03-10', 'amount' => -450]);

        $this->getJson("/api/reports/category-trend?category_id={$category->id}&from=2026-01&to=2026-03")
            ->assertOk()
            ->assertJsonPath('category.id', $category->id)
            ->assertJsonCount(3, 'months')
            ->assertJsonPath('months.0.total', 300) // januar
            ->assertJsonPath('months.1.total', 0)   // februar
            ->assertJsonPath('months.2.total', 450); // mars
    }

    public function test_nettoformue_summerer_kontosaldoer_ved_maanedsslutt(): void
    {
        $bank = Account::factory()->create(['on_budget' => true]);
        $loan = Account::factory()->create(['on_budget' => false, 'type' => 'loan']);

        $bank->transactions()->create(['date' => '2026-01-01', 'amount' => 10000, 'is_starting_balance' => true]);
        $loan->transactions()->create(['date' => '2026-01-01', 'amount' => -50000, 'is_starting_balance' => true]);
        // Februar: bruk 2000 fra banken.
        $bank->transactions()->create(['date' => '2026-02-10', 'amount' => -2000]);

        $this->getJson('/api/reports/net-worth?from=2026-01&to=2026-02')
            ->assertOk()
            ->assertJsonPath('months.0.assets', 10000)
            ->assertJsonPath('months.0.debt', 50000)
            ->assertJsonPath('months.0.net', -40000)
            ->assertJsonPath('months.1.assets', 8000)
            ->assertJsonPath('months.1.net', -42000);
    }

    public function test_konto_utenfor_budsjett_teller_ikke_som_forbruk(): void
    {
        $category = Category::factory()->create();
        $tracking = Account::factory()->tracking()->create();

        $tracking->transactions()->create(['category_id' => $category->id, 'date' => '2026-01-10', 'amount' => -999]);

        $this->getJson('/api/reports/spending?from=2026-01&to=2026-01')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }
}
