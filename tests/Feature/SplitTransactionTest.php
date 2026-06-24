<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SplitTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw']);
    }

    /**
     * Finn kategori-noden i budsjettvisningen uavhengig av gruppe-/sortering.
     *
     * @param  array<string, mixed>  $budget
     * @return array<string, mixed>
     */
    private function categoryNode(array $budget, int $categoryId): array
    {
        foreach ($budget['groups'] as $group) {
            foreach ($group['categories'] as $category) {
                if ($category['id'] === $categoryId) {
                    return $category;
                }
            }
        }

        $this->fail("Fant ikke kategori {$categoryId} i budsjettvisningen.");
    }

    public function test_splitter_transaksjon_paa_flere_kategorier(): void
    {
        $account = Account::factory()->create(['on_budget' => true]);
        $mat = Category::factory()->create();
        $klaer = Category::factory()->create();
        $tx = Transaction::factory()->for($account)->create([
            'date' => '2026-03-15',
            'amount' => -1000,
            'category_id' => null,
        ]);

        $this->putJson("/api/transactions/{$tx->id}", [
            'splits' => [
                ['category_id' => $mat->id, 'amount' => -600],
                ['category_id' => $klaer->id, 'amount' => -400],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.is_split', true)
            ->assertJsonPath('data.category_id', null)
            ->assertJsonCount(2, 'data.splits');

        $this->assertDatabaseHas('transaction_splits', [
            'transaction_id' => $tx->id, 'category_id' => $mat->id, 'amount' => -600,
        ]);
        $this->assertDatabaseHas('transaction_splits', [
            'transaction_id' => $tx->id, 'category_id' => $klaer->id, 'amount' => -400,
        ]);

        // Pengeraden er uendret → saldo påvirkes ikke av splitten.
        $this->getJson("/api/accounts/{$account->id}")->assertJsonPath('data.balance', -1000);

        // Aktiviteten fordeles på de to kategoriene.
        $budget = $this->getJson('/api/budget?month=2026-03')->json();
        $this->assertEquals(-600, $this->categoryNode($budget, $mat->id)['activity']);
        $this->assertEquals(-400, $this->categoryNode($budget, $klaer->id)['activity']);
    }

    public function test_splitt_sum_maa_matche_belopet(): void
    {
        $account = Account::factory()->create(['on_budget' => true]);
        $a = Category::factory()->create();
        $b = Category::factory()->create();
        $tx = Transaction::factory()->for($account)->create(['amount' => -1000, 'category_id' => null]);

        $this->putJson("/api/transactions/{$tx->id}", [
            'splits' => [
                ['category_id' => $a->id, 'amount' => -600],
                ['category_id' => $b->id, 'amount' => -300],
            ],
        ])->assertStatus(422)->assertJsonValidationErrorFor('splits');

        $this->assertDatabaseCount('transaction_splits', 0);
    }

    public function test_splitt_krever_samme_fortegn(): void
    {
        $account = Account::factory()->create(['on_budget' => true]);
        $a = Category::factory()->create();
        $b = Category::factory()->create();
        $tx = Transaction::factory()->for($account)->create(['amount' => -1000, 'category_id' => null]);

        $this->putJson("/api/transactions/{$tx->id}", [
            'splits' => [
                ['category_id' => $a->id, 'amount' => -1200],
                ['category_id' => $b->id, 'amount' => 200],
            ],
        ])->assertStatus(422)->assertJsonValidationErrorFor('splits');
    }

    public function test_splitt_krever_minst_to_linjer(): void
    {
        $account = Account::factory()->create(['on_budget' => true]);
        $a = Category::factory()->create();
        $tx = Transaction::factory()->for($account)->create(['amount' => -1000, 'category_id' => null]);

        $this->putJson("/api/transactions/{$tx->id}", [
            'splits' => [['category_id' => $a->id, 'amount' => -1000]],
        ])->assertStatus(422)->assertJsonValidationErrorFor('splits');
    }

    public function test_splittet_forbruk_teller_ikke_mot_rta_og_identiteten_holder(): void
    {
        $account = Account::factory()->create(['on_budget' => true]);
        $mat = Category::factory()->create();
        $klaer = Category::factory()->create();

        // Inntekt inn på RTA + et splittet forbruk.
        Transaction::factory()->for($account)->create(['date' => '2026-03-01', 'amount' => 2000, 'category_id' => null, 'rta' => true]);
        $tx = Transaction::factory()->for($account)->create(['date' => '2026-03-10', 'amount' => -1000, 'category_id' => null]);

        $this->putJson("/api/transactions/{$tx->id}", [
            'splits' => [
                ['category_id' => $mat->id, 'amount' => -600],
                ['category_id' => $klaer->id, 'amount' => -400],
            ],
        ])->assertOk();

        $budget = $this->getJson('/api/budget?month=2026-03')->json();

        // Det splittede forbruket skal IKKE telle som RTA-tilflyt (kun inntekten gjør det).
        $this->assertEquals(2000, $budget['ready_to_assign']);

        // Identiteten RTA + Σtilgjengelig = penger på konto (2000 − 1000 = 1000).
        $available = array_sum(array_column($budget['groups'], 'available'));
        $this->assertEquals(1000, round($budget['ready_to_assign'] + $available, 2));
    }

    public function test_av_splitting_fjerner_splittlinjene(): void
    {
        $account = Account::factory()->create(['on_budget' => true]);
        $a = Category::factory()->create();
        $b = Category::factory()->create();
        $tx = Transaction::factory()->for($account)->create(['amount' => -1000, 'category_id' => null]);

        $this->putJson("/api/transactions/{$tx->id}", [
            'splits' => [
                ['category_id' => $a->id, 'amount' => -600],
                ['category_id' => $b->id, 'amount' => -400],
            ],
        ])->assertOk();

        $this->putJson("/api/transactions/{$tx->id}", ['splits' => []])
            ->assertOk()
            ->assertJsonPath('data.is_split', false);

        $this->assertDatabaseCount('transaction_splits', 0);
    }

    public function test_setter_kategori_fjerner_eksisterende_splitt(): void
    {
        $account = Account::factory()->create(['on_budget' => true]);
        $a = Category::factory()->create();
        $b = Category::factory()->create();
        $mat = Category::factory()->create();
        $tx = Transaction::factory()->for($account)->create(['amount' => -1000, 'category_id' => null]);

        $this->putJson("/api/transactions/{$tx->id}", [
            'splits' => [
                ['category_id' => $a->id, 'amount' => -600],
                ['category_id' => $b->id, 'amount' => -400],
            ],
        ])->assertOk();

        $this->putJson("/api/transactions/{$tx->id}", ['category_id' => $mat->id])
            ->assertOk()
            ->assertJsonPath('data.is_split', false)
            ->assertJsonPath('data.category_id', $mat->id);

        $this->assertDatabaseCount('transaction_splits', 0);
    }

    public function test_beloep_kan_ikke_endres_paa_splittet_rad_uten_aa_oppdatere_splittene(): void
    {
        $account = Account::factory()->create(['on_budget' => true]);
        $mat = Category::factory()->create();
        $klaer = Category::factory()->create();

        Transaction::factory()->for($account)->create(['date' => '2026-03-01', 'amount' => 2000, 'category_id' => null, 'rta' => true]);
        $tx = Transaction::factory()->for($account)->create(['date' => '2026-03-10', 'amount' => -1000, 'category_id' => null]);

        $this->putJson("/api/transactions/{$tx->id}", [
            'splits' => [
                ['category_id' => $mat->id, 'amount' => -600],
                ['category_id' => $klaer->id, 'amount' => -400],
            ],
        ])->assertOk();

        // Å endre beløpet uten å sende nye splittlinjer ville desynke splittsummen
        // (−1000) fra pengeraden (−1500) og velte budsjettet → avvises.
        $this->putJson("/api/transactions/{$tx->id}", ['amount' => -1500])
            ->assertStatus(422);

        // Raden og splittlinjene er uendret.
        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'amount' => -1000, 'is_split' => true]);
        $this->assertDatabaseHas('transaction_splits', [
            'transaction_id' => $tx->id, 'category_id' => $mat->id, 'amount' => -600,
        ]);

        // Identiteten RTA + Σtilgjengelig = penger på konto (2000 − 1000 = 1000) holder.
        $budget = $this->getJson('/api/budget?month=2026-03')->json();
        $available = array_sum(array_column($budget['groups'], 'available'));
        $this->assertEquals(1000, round($budget['ready_to_assign'] + $available, 2));
        $this->getJson("/api/accounts/{$account->id}")->assertJsonPath('data.balance', 1000);
    }

    public function test_beloep_kan_endres_naar_nye_splittlinjer_foelger_med(): void
    {
        $account = Account::factory()->create(['on_budget' => true]);
        $mat = Category::factory()->create();
        $klaer = Category::factory()->create();
        $tx = Transaction::factory()->for($account)->create(['date' => '2026-03-10', 'amount' => -1000, 'category_id' => null]);

        $this->putJson("/api/transactions/{$tx->id}", [
            'splits' => [
                ['category_id' => $mat->id, 'amount' => -600],
                ['category_id' => $klaer->id, 'amount' => -400],
            ],
        ])->assertOk();

        // Beløp + nye splittlinjer sammen er konsistent og tillatt.
        $this->putJson("/api/transactions/{$tx->id}", [
            'amount' => -1500,
            'splits' => [
                ['category_id' => $mat->id, 'amount' => -900],
                ['category_id' => $klaer->id, 'amount' => -600],
            ],
        ])->assertOk()->assertJsonPath('data.is_split', true);

        $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'amount' => -1500]);
        $this->getJson("/api/accounts/{$account->id}")->assertJsonPath('data.balance', -1500);
    }

    public function test_overforing_budsjett_til_overvaaket_kan_splittes(): void
    {
        $budget = Account::factory()->create(['on_budget' => true]);
        $tracking = Account::factory()->tracking()->create();
        $sparing = Category::factory()->create();
        $reise = Category::factory()->create();

        $this->postJson('/api/transfers', [
            'from_account_id' => $budget->id,
            'to_account_id' => $tracking->id,
            'amount' => 1000,
            'date' => '2026-03-10',
            'category_id' => $sparing->id,
        ])->assertCreated();

        $budgetLeg = $budget->transactions()->firstOrFail();

        $this->putJson("/api/transactions/{$budgetLeg->id}", [
            'splits' => [
                ['category_id' => $sparing->id, 'amount' => -600],
                ['category_id' => $reise->id, 'amount' => -400],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.is_split', true)
            ->assertJsonCount(2, 'data.splits');
    }

    public function test_vanlig_overforing_kan_ikke_splittes(): void
    {
        $a = Account::factory()->create(['on_budget' => true]);
        $b = Account::factory()->create(['on_budget' => true]);
        $cat = Category::factory()->create();
        $cat2 = Category::factory()->create();

        $this->postJson('/api/transfers', [
            'from_account_id' => $a->id,
            'to_account_id' => $b->id,
            'amount' => 500,
            'date' => '2026-03-10',
        ])->assertCreated();

        $leg = $a->transactions()->firstOrFail();

        $this->putJson("/api/transactions/{$leg->id}", [
            'splits' => [
                ['category_id' => $cat->id, 'amount' => -300],
                ['category_id' => $cat2->id, 'amount' => -200],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('transaction_splits', 0);
    }

    public function test_sletting_av_transaksjon_fjerner_splittene(): void
    {
        $account = Account::factory()->create(['on_budget' => true]);
        $a = Category::factory()->create();
        $b = Category::factory()->create();
        $tx = Transaction::factory()->for($account)->create(['amount' => -1000, 'category_id' => null]);

        $this->putJson("/api/transactions/{$tx->id}", [
            'splits' => [
                ['category_id' => $a->id, 'amount' => -600],
                ['category_id' => $b->id, 'amount' => -400],
            ],
        ])->assertOk();

        $this->deleteJson("/api/transactions/{$tx->id}")->assertNoContent();

        $this->assertDatabaseCount('transaction_splits', 0);
    }

    public function test_kategori_transaksjoner_inkluderer_splittlinjer(): void
    {
        $account = Account::factory()->create(['on_budget' => true]);
        $mat = Category::factory()->create();
        $klaer = Category::factory()->create();
        $tx = Transaction::factory()->for($account)->create([
            'date' => '2026-03-15',
            'amount' => -1000,
            'category_id' => null,
            'payee' => 'Storkjøp',
        ]);

        $this->putJson("/api/transactions/{$tx->id}", [
            'splits' => [
                ['category_id' => $mat->id, 'amount' => -600],
                ['category_id' => $klaer->id, 'amount' => -400],
            ],
        ])->assertOk();

        $this->getJson("/api/budget/2026-03/categories/{$mat->id}/transactions")
            ->assertOk()
            ->assertJsonCount(1, 'transactions')
            ->assertJsonPath('transactions.0.amount', -600)
            ->assertJsonPath('transactions.0.payee', 'Storkjøp');
    }

    public function test_forbruksrapport_teller_splittlinjer(): void
    {
        $account = Account::factory()->create(['on_budget' => true]);
        $mat = Category::factory()->create();
        $klaer = Category::factory()->create();
        $tx = Transaction::factory()->for($account)->create([
            'date' => '2026-03-15',
            'amount' => -1000,
            'category_id' => null,
        ]);

        $this->putJson("/api/transactions/{$tx->id}", [
            'splits' => [
                ['category_id' => $mat->id, 'amount' => -600],
                ['category_id' => $klaer->id, 'amount' => -400],
            ],
        ])->assertOk();

        $this->getJson('/api/reports/spending?from=2026-03&to=2026-03')
            ->assertOk()
            ->assertJsonPath('total', 1000);
    }
}
