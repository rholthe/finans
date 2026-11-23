<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\BankConnection;
use App\Models\BudgetAllocation;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\ScheduledTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * /api/assistent/* – lese-API-et for den personlige assistenten.
 */
class AssistantApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-noekkel-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.assistent.token' => self::TOKEN]);
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(12, 0));
    }

    private function api(string $path)
    {
        return $this->withToken(self::TOKEN)->getJson('/api/assistent/'.$path);
    }

    /** Budsjettkonto «Brukskonto» med en kategori «Dagligvarer» i gruppen «Hverdag». */
    private function seedBudget(): array
    {
        $konto = Account::factory()->create(['name' => 'Brukskonto', 'type' => AccountType::Bank, 'on_budget' => true]);
        $group = CategoryGroup::factory()->create(['name' => 'Hverdag']);
        $mat = Category::factory()->create(['name' => 'Dagligvarer', 'category_group_id' => $group->id]);
        $ute = Category::factory()->create(['name' => 'Restaurant', 'category_group_id' => $group->id]);
        $konto->transactions()->create(['date' => '2026-11-01', 'amount' => 30000, 'payee' => 'Lønn', 'rta' => true]);
        BudgetAllocation::create(['category_id' => $mat->id, 'month' => '2026-11-01', 'assigned' => 5000]);
        BudgetAllocation::create(['category_id' => $ute->id, 'month' => '2026-11-01', 'assigned' => 500]);
        $konto->transactions()->create(['date' => '2026-11-10', 'amount' => -168.10, 'payee' => 'Rema 1000', 'bank_description' => 'VISA 1234 REMA 1000 LYSAKER', 'category_id' => $mat->id]);
        $konto->transactions()->create(['date' => '2026-11-12', 'amount' => -800, 'payee' => 'Olivia', 'category_id' => $ute->id]);
        $konto->transactions()->create(['date' => '2026-11-13', 'amount' => -99, 'payee' => 'Ukjent butikk']);

        return compact('konto', 'mat', 'ute');
    }

    /* ---------- Sperrer ---------- */

    public function test_uten_noekkel_avvises(): void
    {
        $this->getJson('/api/assistent/overview')->assertStatus(401);
    }

    public function test_feil_noekkel_avvises(): void
    {
        $this->withToken('feil')->getJson('/api/assistent/overview')->assertStatus(401);
    }

    public function test_tom_noekkel_i_config_stenger_alt(): void
    {
        config(['services.assistent.token' => '']);
        $this->withToken('')->getJson('/api/assistent/overview')->assertStatus(401);
        $this->withToken(self::TOKEN)->getJson('/api/assistent/overview')->assertStatus(401);
    }

    public function test_via_caddy_avvises_selv_med_riktig_noekkel(): void
    {
        $this->withToken(self::TOKEN)->withHeader('X-Forwarded-For', '203.0.113.7')
            ->getJson('/api/assistent/overview')->assertStatus(403);
        $this->withToken(self::TOKEN)->withHeader('X-Forwarded-Proto', 'https')
            ->getJson('/api/assistent/overview')->assertStatus(403);
    }

    public function test_skriving_avvises_selv_med_noekkel(): void
    {
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->withToken(self::TOKEN)->json($method, '/api/assistent/overview')->assertStatus(405);
        }
    }

    public function test_noekkelen_gir_ikke_tilgang_til_appens_vanlige_api(): void
    {
        $this->withToken(self::TOKEN)->getJson('/api/accounts')->assertStatus(401);
        $this->withToken(self::TOKEN)->postJson('/api/transfers', [])->assertStatus(401);
    }

    public function test_innlogget_oekt_uten_noekkel_slipper_ikke_inn(): void
    {
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw'])->assertOk();
        $this->getJson('/api/assistent/overview')->assertStatus(401);
    }

    public function test_ukjent_rapport_gir_404(): void
    {
        $this->api('reports/hemmelig')->assertStatus(404);
    }

    /* ---------- Innhold ---------- */

    public function test_oversikt(): void
    {
        $this->seedBudget();
        BankConnection::create(['provider' => 'enablebanking', 'institution_id' => 'x', 'name' => 'DNB', 'consent_id' => 'c', 'status' => 'linked', 'valid_until' => now()->addDays(5)]);
        BankConnection::create(['provider' => 'enablebanking', 'institution_id' => 'y', 'name' => 'Bulder', 'consent_id' => 'd', 'status' => 'linked', 'valid_until' => now()->addDays(60)]);

        $r = $this->api('overview')->assertOk()
            ->assertJsonPath('month', '2026-11')
            ->assertJsonPath('ready_to_assign', 24500)
            ->assertJsonPath('accounts.0.name', 'Brukskonto')
            ->assertJsonPath('accounts.0.balance', 28932.9)
            ->assertJsonPath('overspent.0.category', 'Restaurant')
            ->assertJsonPath('overspent.0.available', -300)
            ->assertJsonPath('uncategorized.count', 1)
            ->assertJsonPath('bank_connections_expiring.0.name', 'DNB')
            ->assertJsonCount(1, 'bank_connections_expiring');

        // Ingen id-er, kontonumre eller samtykke-id-er i svaret
        $this->assertStringNotContainsString('consent', $r->getContent());
        $this->assertArrayNotHasKey('id', $r->json('accounts.0'));
    }

    public function test_budsjett_filtrert_paa_kategori(): void
    {
        $this->seedBudget();

        $this->api('budget?category=dagligv')->assertOk()
            ->assertJsonCount(1, 'groups')
            ->assertJsonCount(1, 'groups.0.categories')
            ->assertJsonPath('groups.0.categories.0.category', 'Dagligvarer')
            ->assertJsonPath('groups.0.categories.0.assigned', 5000)
            ->assertJsonPath('groups.0.categories.0.activity', -168.1)
            ->assertJsonPath('groups.0.categories.0.available', 4831.9);

        $this->api('budget?month=2026-13')->assertStatus(422);
    }

    public function test_transaksjonssoek_med_banktekst_og_filtre(): void
    {
        ['mat' => $mat] = $this->seedBudget();

        $this->api('transactions?q=lysaker')->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('transactions.0.payee', 'Rema 1000')
            ->assertJsonPath('transactions.0.bank_text', 'VISA 1234 REMA 1000 LYSAKER')
            ->assertJsonPath('transactions.0.category', 'Dagligvarer')
            ->assertJsonPath('transactions.0.account', 'Brukskonto');

        $this->api('transactions?category=restaurant')->assertJsonPath('count', 1)->assertJsonPath('transactions.0.amount', -800);
        $this->api('transactions?uncategorized=1')->assertJsonPath('count', 1)->assertJsonPath('transactions.0.uncategorized', true);
        $this->api('transactions?min_amount=-200&max_amount=-100')->assertJsonPath('count', 1);
        $this->api('transactions?from=2026-11-11&to=2026-11-12')->assertJsonPath('count', 1);
        $this->api('transactions?account=bruks&limit=2')
            ->assertJsonPath('count', 4)
            ->assertJsonPath('returned', 2)
            ->assertJsonPath('transactions.0.date', '2026-11-13'); // nyeste først
        $this->api('transactions?limit=500')->assertStatus(422);
    }

    public function test_splitt_vises_og_treffes_av_kategorifilter(): void
    {
        ['konto' => $konto, 'mat' => $mat, 'ute' => $ute] = $this->seedBudget();
        $t = $konto->transactions()->create(['date' => '2026-11-14', 'amount' => -1000, 'payee' => 'Coop Mega', 'is_split' => true]);
        $t->splits()->create(['category_id' => $mat->id, 'amount' => -700]);
        $t->splits()->create(['category_id' => $ute->id, 'amount' => -300, 'memo' => 'kafé']);

        $r = $this->api('transactions?q=coop')->assertOk();
        $this->assertCount(2, $r->json('transactions.0.splits'));
        $this->assertArrayNotHasKey('category', $r->json('transactions.0'));
        $this->assertArrayNotHasKey('uncategorized', $r->json('transactions.0'));

        $this->api('transactions?category=restaurant')->assertJsonPath('count', 2);
    }

    public function test_rapporter(): void
    {
        $this->seedBudget();

        $this->api('reports/spending?from=2026-11&to=2026-11')->assertOk()
            ->assertJsonPath('total', 968.1)
            ->assertJsonPath('groups.0.group', 'Hverdag')
            ->assertJsonPath('groups.0.categories.0.category', 'Restaurant');

        $this->api('reports/income-expense?from=2026-11&to=2026-11')->assertOk()->assertJsonPath('months.0.month', '2026-11');
        $this->api('reports/net-worth?from=2026-10&to=2026-11')->assertOk()->assertJsonCount(2, 'months');
        $this->api('reports/category-trend?category=dagligvarer&from=2026-11&to=2026-11')->assertOk()
            ->assertJsonPath('category', 'Dagligvarer')->assertJsonPath('total', 168.1);
        $this->api('reports/category-trend?category=finnesikke')->assertStatus(404);
        $this->api('reports/category-trend')->assertStatus(422);
    }

    public function test_kommende_planlagte(): void
    {
        ['konto' => $konto, 'mat' => $mat] = $this->seedBudget();
        ScheduledTransaction::factory()->create([
            'account_id' => $konto->id, 'category_id' => $mat->id, 'amount' => -449, 'payee' => 'Telenor',
            'start_date' => '2026-11-20', 'next_date' => '2026-11-20',
        ]);
        ScheduledTransaction::factory()->create([
            'account_id' => $konto->id, 'amount' => -9000, 'payee' => 'Husleie',
            'start_date' => '2027-01-01', 'next_date' => '2027-01-01',
        ]);

        $this->api('upcoming?days=14')->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.payee', 'Telenor')
            ->assertJsonPath('items.0.date', '2026-11-20')
            ->assertJsonPath('items.0.frequency', 'monthly');

        $this->api('upcoming?days=60')->assertJsonPath('count', 3); // Telenor 20.11 og 20.12, husleie 1.1
        $this->api('upcoming?days=400')->assertStatus(422);
    }

    public function test_forfalte_planlagte_posteres_ved_lesing(): void
    {
        ['konto' => $konto] = $this->seedBudget();
        ScheduledTransaction::factory()->create([
            'account_id' => $konto->id, 'amount' => -100, 'payee' => 'Forfalt',
            'start_date' => '2026-11-14', 'next_date' => '2026-11-14',
        ]);

        $this->api('transactions?q=Forfalt')->assertJsonPath('count', 1);
    }

    public function test_laan(): void
    {
        $laan = Account::factory()->create(['name' => 'Boliglån', 'type' => AccountType::Loan, 'on_budget' => false, 'interest_rate' => 5]);
        $laan->transactions()->create(['date' => '2026-01-01', 'amount' => -1000000, 'is_starting_balance' => true]);
        foreach (range(5, 10) as $m) {
            $laan->transactions()->create(['date' => sprintf('2026-%02d-15', $m), 'amount' => 10000]);
        }

        $r = $this->api('loans')->assertOk()
            ->assertJsonPath('loans.0.account', 'Boliglån')
            ->assertJsonPath('loans.0.balance', -940000)
            ->assertJsonPath('loans.0.avg_payment', 10000);
        $this->assertNotNull($r->json('loans.0.payoff_month'));
        $this->assertNotEmpty($r->json('loans.0.yearly'));
    }
}
