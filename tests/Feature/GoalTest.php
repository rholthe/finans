<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Goal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GoalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw']);
    }

    private function category(int $sortOrder = 0): Category
    {
        return Category::factory()->create(['sort_order' => $sortOrder]);
    }

    private function income(float $amount, string $date = '2026-01-01'): void
    {
        Account::factory()->create(['on_budget' => true])
            ->transactions()->create(['date' => $date, 'amount' => $amount, 'rta' => true]);
    }

    // --- Mål-CRUD ---

    public function test_setter_maal_via_endepunkt(): void
    {
        $category = $this->category();

        $this->putJson("/api/categories/{$category->id}/goal", [
            'type' => 'monthly',
            'target_amount' => 500,
        ])
            ->assertOk()
            ->assertJsonPath('type', 'monthly')
            ->assertJsonPath('target_amount', 500);

        $this->assertDatabaseHas('goals', ['category_id' => $category->id, 'type' => 'monthly']);
    }

    public function test_datofestet_maal_krever_dato(): void
    {
        $category = $this->category();

        $this->putJson("/api/categories/{$category->id}/goal", [
            'type' => 'target_balance_by_date',
            'target_amount' => 1200,
        ])->assertStatus(422);
    }

    public function test_sletter_maal(): void
    {
        $category = $this->category();
        Goal::factory()->monthly(500)->create(['category_id' => $category->id]);

        $this->deleteJson("/api/categories/{$category->id}/goal")->assertNoContent();

        $this->assertDatabaseMissing('goals', ['category_id' => $category->id]);
    }

    // --- Beregning av «trengs denne måneden» ---

    public function test_monthly_maal_trenger_resten_opp_til_target(): void
    {
        $category = $this->category();
        Goal::factory()->monthly(500)->create(['category_id' => $category->id]);

        $this->getJson('/api/budget?month=2026-01')
            ->assertJsonPath('groups.0.categories.0.goal.type', 'monthly')
            ->assertJsonPath('groups.0.categories.0.needed', 500);

        $this->putJson("/api/budget/2026-01/categories/{$category->id}", ['assigned' => 200]);

        $this->getJson('/api/budget?month=2026-01')
            ->assertJsonPath('groups.0.categories.0.needed', 300);
    }

    public function test_target_balance_trenger_resten_opp_til_tilgjengelig(): void
    {
        $category = $this->category();
        Goal::factory()->targetBalance(1000)->create(['category_id' => $category->id]);

        $this->getJson('/api/budget?month=2026-01')
            ->assertJsonPath('groups.0.categories.0.needed', 1000);

        $this->putJson("/api/budget/2026-01/categories/{$category->id}", ['assigned' => 1000]);

        $this->getJson('/api/budget?month=2026-01')
            ->assertJsonPath('groups.0.categories.0.needed', 0);

        // Spart opp – neste måned trengs ingenting mer (ruller over)
        $this->getJson('/api/budget?month=2026-02')
            ->assertJsonPath('groups.0.categories.0.needed', 0);
    }

    public function test_target_balance_krediterer_rollover_og_ignorerer_maanedens_forbruk(): void
    {
        // Løpende utgift (f.eks. dagligvarer): ha 14000 tilgjengelig hver måned.
        $category = $this->category();
        Goal::factory()->targetBalance(14000)->create(['category_id' => $category->id]);

        // Bygg rullering: tildel 3000 i januar (uten forbruk) → ruller til februar.
        $this->putJson("/api/budget/2026-01/categories/{$category->id}", ['assigned' => 3000]);

        // Februar: rullering 3000 ⇒ trenger 11000 opp til 14000.
        $this->getJson('/api/budget?month=2026-02')
            ->assertJsonPath('groups.0.categories.0.needed', 11000);

        // Tildel 11000 ⇒ 3000 + 11000 = 14000 tilgjengelig ved månedsstart ⇒ mål nådd.
        $this->putJson("/api/budget/2026-02/categories/{$category->id}", ['assigned' => 11000]);
        $this->getJson('/api/budget?month=2026-02')
            ->assertJsonPath('groups.0.categories.0.needed', 0);

        // Bruk 5000 i februar: forbruket teller ikke mot målet – fortsatt oppfylt –
        // men reduserer faktisk tilgjengelig saldo.
        Account::factory()->create(['on_budget' => true])
            ->transactions()->create([
                'category_id' => $category->id,
                'date' => '2026-02-15',
                'amount' => -5000,
            ]);
        $this->getJson('/api/budget?month=2026-02')
            ->assertJsonPath('groups.0.categories.0.needed', 0)
            ->assertJsonPath('groups.0.categories.0.available', 9000);
    }

    public function test_datofestet_maal_fordeler_jevnt_over_gjenstaaende_maaneder(): void
    {
        $category = $this->category();
        Goal::factory()->targetBalanceByDate(1200, '2026-12-01')->create(['category_id' => $category->id]);

        // Januar → desember = 12 måneder → 1200 / 12 = 100 per måned
        $this->getJson('/api/budget?month=2026-01')
            ->assertJsonPath('groups.0.categories.0.needed', 100);
    }

    // --- Auto-allokering ---

    public function test_auto_assign_fyller_maal_i_rekkefolge_begrenset_av_ready_to_assign(): void
    {
        $this->income(600);
        $group = CategoryGroup::factory()->create();
        $first = Category::factory()->for($group, 'group')->create(['sort_order' => 1]);
        $second = Category::factory()->for($group, 'group')->create(['sort_order' => 2]);
        Goal::factory()->monthly(500)->create(['category_id' => $first->id]);
        Goal::factory()->monthly(300)->create(['category_id' => $second->id]);

        $this->postJson('/api/budget/2026-01/auto-assign', ['strategy' => 'fund-goals'])
            ->assertOk()
            ->assertJsonPath('groups.0.categories.0.assigned', 500)
            ->assertJsonPath('groups.0.categories.1.assigned', 100) // resten av RTA
            ->assertJsonPath('ready_to_assign', 0);
    }

    public function test_auto_assign_avgrenset_til_valgte_kategorier(): void
    {
        $this->income(2000);
        $group = CategoryGroup::factory()->create();
        $first = Category::factory()->for($group, 'group')->create(['sort_order' => 1]);
        $second = Category::factory()->for($group, 'group')->create(['sort_order' => 2]);
        Goal::factory()->monthly(500)->create(['category_id' => $first->id]);
        Goal::factory()->monthly(300)->create(['category_id' => $second->id]);

        // Kun den andre kategorien er valgt → kun den fylles.
        $this->postJson('/api/budget/2026-01/auto-assign', [
            'strategy' => 'fund-goals',
            'category_ids' => [$second->id],
        ])
            ->assertOk()
            ->assertJsonPath('groups.0.categories.0.assigned', 0)
            ->assertJsonPath('groups.0.categories.1.assigned', 300);
    }

    public function test_auto_assign_dekker_overtrekk(): void
    {
        $this->income(1000);
        $category = $this->category();
        Account::factory()->create(['on_budget' => true])
            ->transactions()->create([
                'category_id' => $category->id,
                'date' => '2026-01-20',
                'amount' => -150,
            ]);

        $this->getJson('/api/budget?month=2026-01')
            ->assertJsonPath('groups.0.categories.0.available', -150);

        $this->postJson('/api/budget/2026-01/auto-assign', ['strategy' => 'cover-overspending'])
            ->assertOk()
            ->assertJsonPath('groups.0.categories.0.assigned', 150)
            ->assertJsonPath('groups.0.categories.0.available', 0);
    }

    public function test_fund_enkeltkategori_opp_til_maal(): void
    {
        $this->income(1000);
        $category = $this->category();
        Goal::factory()->monthly(500)->create(['category_id' => $category->id]);

        $this->postJson("/api/budget/2026-01/categories/{$category->id}/fund")
            ->assertOk()
            ->assertJsonPath('groups.0.categories.0.assigned', 500)
            ->assertJsonPath('groups.0.categories.0.needed', 0);
    }
}
