<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UncategorizedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw']);
    }

    public function test_scope_teller_kun_ukategorisert_forbruk(): void
    {
        $account = Account::factory()->create();
        $base = ['account_id' => $account->id, 'date' => '2026-06-10'];

        // Teller:
        Transaction::create([...$base, 'amount' => -300]); // glemt forbruk
        // Teller ikke:
        Transaction::create([...$base, 'amount' => 5000, 'rta' => true]); // bevisst RTA (lønn)
        Transaction::create([...$base, 'amount' => -100, 'category_id' => Category::factory()->create()->id]);
        Transaction::create([...$base, 'amount' => -200, 'is_starting_balance' => true]);
        Transaction::create([...$base, 'amount' => -50, 'pending' => true]);
        $legA = Transaction::create([...$base, 'amount' => -25]);
        $legB = Transaction::create([...$base, 'amount' => 25, 'transfer_id' => $legA->id]);
        $legA->update(['transfer_id' => $legB->id]); // begge ben er nå en overføring

        // Ukategorisert på overvåket konto teller ikke.
        $tracking = Account::factory()->tracking()->create();
        Transaction::create(['account_id' => $tracking->id, 'date' => '2026-06-10', 'amount' => -999]);

        $this->assertSame(1, Transaction::query()->needsCategorization()->count());
    }

    public function test_konto_api_eksponerer_uncategorized_count(): void
    {
        $account = Account::factory()->create(['name' => 'Brukskonto']);
        $account->transactions()->createMany([
            ['date' => '2026-06-10', 'amount' => -300],
            ['date' => '2026-06-11', 'amount' => -120],
            ['date' => '2026-06-12', 'amount' => 5000, 'rta' => true],
        ]);

        $this->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.uncategorized_count', 2);
    }

    public function test_transaksjonsliste_filtrerer_paa_ukategorisert(): void
    {
        $account = Account::factory()->create();
        $category = Category::factory()->create();
        $account->transactions()->createMany([
            ['date' => '2026-06-10', 'amount' => -300], // ukategorisert – med
            ['date' => '2026-06-11', 'amount' => -100, 'category_id' => $category->id], // uten
            ['date' => '2026-06-12', 'amount' => 5000, 'rta' => true], // uten
        ]);

        $this->getJson("/api/accounts/{$account->id}/transactions?uncategorized=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', -300);
    }

    public function test_velge_rta_setter_rta_nuller_kategori_og_laaser(): void
    {
        $account = Account::factory()->create();
        $tx = Transaction::create(['account_id' => $account->id, 'date' => '2026-06-10', 'amount' => 5000]);

        $this->patchJson("/api/transactions/{$tx->id}", ['rta' => true])
            ->assertOk()
            ->assertJsonPath('data.rta', true)
            ->assertJsonPath('data.category_id', null);

        $this->assertTrue($tx->fresh()->locked);
        $this->assertSame(0, Transaction::query()->needsCategorization()->count());
    }

    public function test_velge_kategori_nullstiller_rta(): void
    {
        $account = Account::factory()->create();
        $category = Category::factory()->create();
        $tx = Transaction::create([
            'account_id' => $account->id, 'date' => '2026-06-10', 'amount' => -300, 'rta' => true,
        ]);

        $this->patchJson("/api/transactions/{$tx->id}", ['category_id' => $category->id, 'rta' => false])
            ->assertOk()
            ->assertJsonPath('data.category_id', $category->id)
            ->assertJsonPath('data.rta', false);
    }

    public function test_avstemmingsjustering_teller_ikke_som_ukategorisert(): void
    {
        $account = Account::factory()->create();
        // Klarert saldo = 0, oppgir 500 → justering på +500 (ukategorisert, men rta=true).
        app(ReconciliationService::class)->reconcile($account, 500, CarbonImmutable::parse('2026-06-15'));

        $this->assertDatabaseHas('transactions', [
            'account_id' => $account->id,
            'payee' => 'Avstemmingsjustering',
            'rta' => true,
        ]);
        $this->assertSame(0, Transaction::query()->needsCategorization()->count());
    }

    public function test_budsjett_varsler_om_ukategorisert_fra_tidligere_maaneder(): void
    {
        $account = Account::factory()->create();
        $account->transactions()->createMany([
            ['date' => '2026-05-20', 'amount' => -300], // tidligere måned – varsles
            ['date' => '2026-06-10', 'amount' => -100], // denne måneden – varsles ikke
        ]);

        $this->getJson('/api/budget?month=2026-06')
            ->assertOk()
            ->assertJsonPath('prior_uncategorized', 1);
    }
}
