<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\ScheduledTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
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

        // Inntekt = 5000 inn på budsjettkonto, eksplisitt plassert i RTA
        $account->transactions()->create([
            'date' => '2026-01-01',
            'amount' => 5000,
            'rta' => true,
            'is_starting_balance' => true,
        ]);
        $this->putJson("/api/budget/2026-01/categories/{$category->id}", ['assigned' => 1000]);

        $this->getJson('/api/budget?month=2026-01')
            ->assertOk()
            ->assertJsonPath('ready_to_assign', 4000);
    }

    public function test_ukategorisert_forbruk_paavirker_ikke_ready_to_assign(): void
    {
        // Kun rader merket rta=true teller mot RTA. Ukategorisert forbruk (inkl.
        // reservert) ligger i «mangler kategori»-restpotten og rører ikke RTA.
        $account = $this->budgetAccount();

        $account->transactions()->create([
            'date' => '2026-01-01',
            'amount' => 5000,
            'rta' => true,
        ]);
        // Ukategorisert forbruk – skal IKKE trekke fra RTA.
        $account->transactions()->create(['date' => '2026-01-10', 'amount' => -1500]);
        // Reservert – heller ikke i restpotten.
        $account->transactions()->create(['date' => '2026-01-11', 'amount' => -200, 'pending' => true]);

        $this->getJson('/api/budget?month=2026-01')
            ->assertOk()
            ->assertJsonPath('ready_to_assign', 5000)
            ->assertJsonPath('uncategorized_count', 1)
            ->assertJsonPath('uncategorized_total', -1500);
    }

    public function test_kategorisert_forbruk_paavirker_ikke_ready_to_assign(): void
    {
        // Regnskapsidentitet: RTA + Σ(tilgjengelig) skal alltid = penger på konto.
        // Kategorisert forbruk flytter penger fra RTA-pott til kategori, men skal
        // aldri «forsvinne» ved å trekkes fra både kontosaldo og kategori.
        $category = $this->category();
        $account = $this->budgetAccount();

        $account->transactions()->create([
            'date' => '2026-01-01',
            'amount' => 5000,
            'rta' => true,
            'is_starting_balance' => true,
        ]);
        $this->putJson("/api/budget/2026-01/categories/{$category->id}", ['assigned' => 1000]);

        $account->transactions()->create([
            'category_id' => $category->id,
            'date' => '2026-01-15',
            'amount' => -300,
        ]);

        $this->getJson('/api/budget?month=2026-01')
            ->assertOk()
            ->assertJsonPath('groups.0.categories.0.available', 700)
            // Forbruket trekkes IKKE fra RTA – kun tildelingen gjør det.
            ->assertJsonPath('ready_to_assign', 4000);

        // Penger på konto (4700) = RTA (4000) + tilgjengelig (700). Ingen lekkasje.
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

    public function test_kategoriaktivitet_viser_transaksjoner_med_konto_og_planlagte(): void
    {
        $category = $this->category();
        $account = Account::factory()->create(['on_budget' => true, 'name' => 'Brukskonto']);

        // Fremtidige datoer slik at planlagt-posten ikke auto-posteres av
        // EnsureScheduledTransactionsPosted-middleware før vi henter visningen.
        $account->transactions()->create([
            'category_id' => $category->id,
            'date' => '2027-01-15',
            'amount' => -300,
            'payee' => 'Rema 1000',
        ]);

        // Transaksjon i en annen måned skal ikke tas med.
        $account->transactions()->create([
            'category_id' => $category->id,
            'date' => '2027-02-01',
            'amount' => -50,
        ]);

        ScheduledTransaction::factory()->startingOn('2027-01-20')->create([
            'account_id' => $account->id,
            'category_id' => $category->id,
            'amount' => -200,
            'payee' => 'Strøm',
        ]);

        $this->getJson("/api/budget/2027-01/categories/{$category->id}/transactions")
            ->assertOk()
            ->assertJsonCount(1, 'transactions')
            ->assertJsonPath('transactions.0.amount', -300)
            ->assertJsonPath('transactions.0.account', 'Brukskonto')
            ->assertJsonPath('transactions.0.payee', 'Rema 1000')
            ->assertJsonCount(1, 'scheduled')
            ->assertJsonPath('scheduled.0.total', -200);
    }

    public function test_flytt_penger_mellom_kategorier_endrer_tildeling_men_ikke_rta(): void
    {
        $from = $this->category();
        $to = $this->category();

        $this->putJson("/api/budget/2026-01/categories/{$from->id}", ['assigned' => 1000]);

        $this->postJson("/api/budget/2026-01/categories/{$from->id}/move", [
            'to_category_id' => $to->id,
            'amount' => 400,
        ])->assertOk();

        $response = $this->getJson('/api/budget?month=2026-01')->assertOk();

        $categories = collect($response->json('groups'))
            ->flatMap(fn (array $group): array => $group['categories'])
            ->keyBy('id');
        $this->assertEquals(600, $categories[$from->id]['assigned']);
        $this->assertEquals(600, $categories[$from->id]['available']);
        $this->assertEquals(400, $categories[$to->id]['assigned']);
        $this->assertEquals(400, $categories[$to->id]['available']);

        // Netto tildeling er uendret (1000), så Ready to Assign påvirkes ikke.
        $response->assertJsonPath('ready_to_assign', -1000);
    }

    public function test_kan_ikke_flytte_mer_enn_tilgjengelig(): void
    {
        $from = $this->category();
        $to = $this->category();

        $this->putJson("/api/budget/2026-01/categories/{$from->id}", ['assigned' => 100]);

        $this->postJson("/api/budget/2026-01/categories/{$from->id}/move", [
            'to_category_id' => $to->id,
            'amount' => 200,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('budget_allocations', [
            'category_id' => $to->id,
        ]);
    }

    public function test_kan_ikke_flytte_til_samme_kategori(): void
    {
        $category = $this->category();
        $this->putJson("/api/budget/2026-01/categories/{$category->id}", ['assigned' => 100]);

        $this->postJson("/api/budget/2026-01/categories/{$category->id}/move", [
            'to_category_id' => $category->id,
            'amount' => 50,
        ])->assertStatus(422);
    }

    public function test_sweep_tommer_valgte_kategorier_til_maalkategori(): void
    {
        $a = $this->category();
        $b = $this->category();
        $target = $this->category();
        $this->putJson("/api/budget/2026-01/categories/{$a->id}", ['assigned' => 1000]);
        $this->putJson("/api/budget/2026-01/categories/{$b->id}", ['assigned' => 500]);

        $this->postJson('/api/budget/2026-01/sweep', [
            'from_category_ids' => [$a->id, $b->id],
            'to_category_id' => $target->id,
        ])->assertOk();

        $response = $this->getJson('/api/budget?month=2026-01')->assertOk();
        $cats = collect($response->json('groups'))
            ->flatMap(fn (array $g): array => $g['categories'])
            ->keyBy('id');

        $this->assertEquals(0, $cats[$a->id]['available']);
        $this->assertEquals(0, $cats[$b->id]['available']);
        $this->assertEquals(1500, $cats[$target->id]['available']);
        // Netto tildeling uendret (1500) → Ready to Assign uberørt.
        $response->assertJsonPath('ready_to_assign', -1500);
    }

    public function test_nullstill_tildeling_for_valgte(): void
    {
        $a = $this->category();
        $this->putJson("/api/budget/2026-01/categories/{$a->id}", ['assigned' => 1000]);

        $this->postJson('/api/budget/2026-01/reset-assignments', ['category_ids' => [$a->id]])
            ->assertOk()
            ->assertJsonPath('groups.0.categories.0.assigned', 0);

        $this->assertDatabaseHas('budget_allocations', ['category_id' => $a->id, 'assigned' => 0]);
    }

    /**
     * Hent kategoriene fra en budsjettrespons, nøklet på id.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function categoriesById(TestResponse $response): Collection
    {
        return collect($response->json('groups'))
            ->flatMap(fn (array $group): array => $group['categories'])
            ->keyBy('id');
    }

    public function test_dekk_overtrekk_fra_kildekategori_lar_rta_vaere(): void
    {
        $overspent = $this->category();
        $donor = $this->category();
        $account = $this->budgetAccount();

        $account->transactions()->create([
            'date' => '2026-01-01',
            'amount' => 5000,
            'rta' => true,
            'is_starting_balance' => true,
        ]);
        $this->putJson("/api/budget/2026-01/categories/{$overspent->id}", ['assigned' => 100]);
        $this->putJson("/api/budget/2026-01/categories/{$donor->id}", ['assigned' => 500]);
        $account->transactions()->create([
            'category_id' => $overspent->id,
            'date' => '2026-01-15',
            'amount' => -300,
        ]);

        $response = $this->postJson("/api/budget/2026-01/categories/{$overspent->id}/cover", [
            'amount' => 200,
            'from_category_id' => $donor->id,
        ])->assertOk();

        $cats = $this->categoriesById($response);
        $this->assertEquals(0, $cats[$overspent->id]['available']);
        $this->assertEquals(300, $cats[$donor->id]['available']);
        // Netto tildeling uendret (600) → RTA uberørt.
        $response->assertJsonPath('ready_to_assign', 4400);
    }

    public function test_dekk_overtrekk_fra_rta_trekker_fra_rta(): void
    {
        $overspent = $this->category();
        $account = $this->budgetAccount();

        $account->transactions()->create([
            'date' => '2026-01-01',
            'amount' => 5000,
            'rta' => true,
            'is_starting_balance' => true,
        ]);
        $this->putJson("/api/budget/2026-01/categories/{$overspent->id}", ['assigned' => 100]);
        $account->transactions()->create([
            'category_id' => $overspent->id,
            'date' => '2026-01-15',
            'amount' => -300,
        ]);

        $response = $this->postJson("/api/budget/2026-01/categories/{$overspent->id}/cover", [
            'amount' => 200,
        ])->assertOk();

        $cats = $this->categoriesById($response);
        $this->assertEquals(0, $cats[$overspent->id]['available']);
        $this->assertEquals(300, $cats[$overspent->id]['assigned']);
        // RTA: 5000 − tildelt 300 = 4700.
        $response->assertJsonPath('ready_to_assign', 4700);
    }

    public function test_dekk_overtrekk_avvises_naar_kilde_mangler_tilgjengelig(): void
    {
        $overspent = $this->category();
        $donor = $this->category();
        $this->putJson("/api/budget/2026-01/categories/{$donor->id}", ['assigned' => 100]);

        $this->postJson("/api/budget/2026-01/categories/{$overspent->id}/cover", [
            'amount' => 200,
            'from_category_id' => $donor->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('budget_allocations', ['category_id' => $overspent->id]);
    }

    public function test_hurtigbudsjett_tildelt_forrige_maaned(): void
    {
        $category = $this->category();
        $this->putJson("/api/budget/2026-01/categories/{$category->id}", ['assigned' => 1000]);

        $response = $this->postJson('/api/budget/2026-02/quick-budget', [
            'strategy' => 'assigned-last-month',
            'category_ids' => [$category->id],
        ])->assertOk();

        $this->assertEquals(1000, $this->categoriesById($response)[$category->id]['assigned']);
    }

    public function test_hurtigbudsjett_brukt_forrige_maaned(): void
    {
        $category = $this->category();
        $account = $this->budgetAccount();
        $account->transactions()->create([
            'category_id' => $category->id,
            'date' => '2026-01-15',
            'amount' => -400,
        ]);

        $response = $this->postJson('/api/budget/2026-02/quick-budget', [
            'strategy' => 'spent-last-month',
            'category_ids' => [$category->id],
        ])->assertOk();

        $this->assertEquals(400, $this->categoriesById($response)[$category->id]['assigned']);
    }

    public function test_hurtigbudsjett_snitt_forbruk_3_mnd_roerer_ikke_uvalgte(): void
    {
        $category = $this->category();
        $other = $this->category();
        $account = $this->budgetAccount();

        foreach (['2026-01-10' => -300, '2026-02-10' => -600, '2026-03-10' => -900] as $date => $amount) {
            $account->transactions()->create([
                'category_id' => $category->id,
                'date' => $date,
                'amount' => $amount,
            ]);
        }

        $response = $this->postJson('/api/budget/2026-04/quick-budget', [
            'strategy' => 'avg-spent-3m',
            'category_ids' => [$category->id],
        ])->assertOk();

        $cats = $this->categoriesById($response);
        // (300 + 600 + 900) / 3 = 600.
        $this->assertEquals(600, $cats[$category->id]['assigned']);
        // Uvalgt kategori er urørt.
        $this->assertEquals(0, $cats[$other->id]['assigned']);
        $this->assertDatabaseMissing('budget_allocations', ['category_id' => $other->id]);
    }
}
