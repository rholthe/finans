<?php

namespace Tests\Feature;

use App\Enums\ScheduleFrequency;
use App\Models\Account;
use App\Models\Category;
use App\Models\ScheduledTransaction;
use App\Models\Transaction;
use App\Services\ScheduledTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ScheduledTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw']);
    }

    private function postDue(): int
    {
        return app(ScheduledTransactionService::class)->postDue();
    }

    // --- CRUD ---

    public function test_oppretter_planlagt_med_next_date_lik_startdato(): void
    {
        $account = Account::factory()->create();
        $start = now()->addMonths(2)->toDateString();

        $this->postJson('/api/scheduled-transactions', [
            'account_id' => $account->id,
            'amount' => -1000,
            'payee' => 'Husleie',
            'frequency' => 'monthly',
            'start_date' => $start,
        ])
            ->assertCreated()
            ->assertJsonPath('data.payee', 'Husleie')
            ->assertJsonPath('data.frequency', 'monthly')
            ->assertJsonPath('data.next_date', $start);
    }

    public function test_avviser_sluttdato_for_startdato(): void
    {
        $account = Account::factory()->create();

        $this->postJson('/api/scheduled-transactions', [
            'account_id' => $account->id,
            'amount' => -1000,
            'frequency' => 'monthly',
            'start_date' => now()->addMonths(2)->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_kan_flytte_neste_forfall_framover_uten_aa_roere_startdato(): void
    {
        // Serie som allerede har postert noen forekomster (brukerens scenario).
        $schedule = ScheduledTransaction::factory()
            ->startingOn('2020-01-12')
            ->create([
                'last_posted_date' => '2020-03-12',
                'next_date' => now()->addMonth()->toDateString(),
            ]);

        $target = now()->addMonths(3)->toDateString();

        $this->patchJson("/api/scheduled-transactions/{$schedule->id}", ['next_date' => $target])
            ->assertOk()
            ->assertJsonPath('data.next_date', $target);

        $schedule->refresh();
        $this->assertSame($target, $schedule->next_date->toDateString());
        // Startdatoen (det historiske ankeret) er uendret.
        $this->assertSame('2020-01-12', $schedule->start_date->toDateString());
    }

    public function test_kan_ikke_sette_neste_forfall_i_fortiden(): void
    {
        $schedule = ScheduledTransaction::factory()->startingOn('2020-01-12')->create();

        $this->patchJson("/api/scheduled-transactions/{$schedule->id}", [
            'next_date' => now()->subDay()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_lister_og_sletter(): void
    {
        $scheduled = ScheduledTransaction::factory()->startingOn('2030-01-01')->create();

        $this->getJson('/api/scheduled-transactions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $scheduled->id);

        $this->deleteJson("/api/scheduled-transactions/{$scheduled->id}")->assertNoContent();
        $this->assertDatabaseMissing('scheduled_transactions', ['id' => $scheduled->id]);
    }

    // --- Postering ---

    public function test_posterer_forfalt_forekomst_og_avanserer(): void
    {
        $this->travelTo('2026-01-15');
        $account = Account::factory()->create();
        $schedule = ScheduledTransaction::factory()
            ->startingOn('2026-01-10')
            ->create(['account_id' => $account->id, 'amount' => -500]);

        $this->assertSame(1, $this->postDue());

        $transaction = $account->transactions()->where('scheduled_transaction_id', $schedule->id)->sole();
        $this->assertSame('2026-01-10', $transaction->date->toDateString());
        $this->assertSame(-500.0, (float) $transaction->amount);
        // Planlagte posteringer låses så regelmotoren aldri overskriver dem.
        $this->assertTrue($transaction->locked);

        $schedule->refresh();
        $this->assertSame('2026-02-10', $schedule->next_date->toDateString());
        $this->assertSame('2026-01-10', $schedule->last_posted_date->toDateString());
    }

    public function test_tar_igjen_flere_bomte_forekomster(): void
    {
        $this->travelTo('2026-01-29');
        ScheduledTransaction::factory()
            ->frequency(ScheduleFrequency::Weekly)
            ->startingOn('2026-01-01')
            ->create();

        // 01-01, 01-08, 01-15, 01-22, 01-29 = 5 forekomster
        $this->assertSame(5, $this->postDue());
        $this->assertDatabaseCount('transactions', 5);
    }

    public function test_stopper_ved_sluttdato(): void
    {
        $this->travelTo('2026-03-01');
        ScheduledTransaction::factory()
            ->frequency(ScheduleFrequency::Weekly)
            ->startingOn('2026-01-01')
            ->create(['end_date' => '2026-01-15']);

        // 01-01, 01-08, 01-15 = 3, deretter stopp
        $this->assertSame(3, $this->postDue());
        $this->assertDatabaseCount('transactions', 3);
    }

    public function test_er_idempotent(): void
    {
        $this->travelTo('2026-01-15');
        ScheduledTransaction::factory()->startingOn('2026-01-10')->create(['amount' => -500]);

        $this->postDue();
        $this->assertSame(0, $this->postDue());
        $this->assertDatabaseCount('transactions', 1);
    }

    // --- Budsjett-projeksjon ---

    public function test_kommende_utgift_senker_projisert_tilgjengelig(): void
    {
        $this->travelTo('2026-01-05');
        $category = Category::factory()->create();
        $account = Account::factory()->create(['on_budget' => true]);

        $this->putJson("/api/budget/2026-01/categories/{$category->id}", ['assigned' => 1000]);

        ScheduledTransaction::factory()
            ->startingOn('2026-01-28')
            ->create(['account_id' => $account->id, 'category_id' => $category->id, 'amount' => -800]);

        $this->getJson('/api/budget?month=2026-01')
            ->assertOk()
            ->assertJsonPath('groups.0.categories.0.available', 1000)
            ->assertJsonPath('groups.0.categories.0.upcoming', -800)
            ->assertJsonPath('groups.0.categories.0.projected_available', 200);
    }

    public function test_kommende_inntekt_hever_projisert_ready_to_assign(): void
    {
        $this->travelTo('2026-01-05');
        $account = Account::factory()->create(['on_budget' => true]);

        ScheduledTransaction::factory()
            ->startingOn('2026-01-20')
            ->create(['account_id' => $account->id, 'category_id' => null, 'amount' => 3000]);

        $this->getJson('/api/budget?month=2026-01')
            ->assertOk()
            ->assertJsonPath('ready_to_assign', 0)
            ->assertJsonPath('upcoming_income', 3000)
            ->assertJsonPath('projected_ready_to_assign', 3000);
    }

    public function test_dekk_overtrekk_dekker_ogsaa_kommende_regninger(): void
    {
        $this->travelTo('2026-01-05');
        $category = Category::factory()->create();
        $account = Account::factory()->create(['on_budget' => true]);

        // Inntekt nok i Ready to Assign, ingen postert aktivitet ennå.
        $account->transactions()->create(['date' => '2026-01-01', 'amount' => 5000]);

        // Kommende regning senere i måneden → projisert tilgjengelig = -800.
        ScheduledTransaction::factory()
            ->startingOn('2026-01-28')
            ->create(['account_id' => $account->id, 'category_id' => $category->id, 'amount' => -800]);

        $this->postJson('/api/budget/2026-01/auto-assign', ['strategy' => 'cover-overspending'])
            ->assertOk()
            ->assertJsonPath('groups.0.categories.0.assigned', 800)
            ->assertJsonPath('groups.0.categories.0.projected_available', 0);
    }

    public function test_middleware_posterer_forfalt_ved_api_kall(): void
    {
        $this->travelTo('2026-01-15');
        $account = Account::factory()->create(['on_budget' => true]);
        ScheduledTransaction::factory()
            ->startingOn('2026-01-10')
            ->create(['account_id' => $account->id, 'amount' => -500]);

        // Et hvilket som helst beskyttet kall skal trigge postering først
        $this->getJson('/api/accounts')->assertOk();

        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_planlagt_til_rta_posteres_med_rta_og_teller_ikke_som_ukategorisert(): void
    {
        $account = Account::factory()->create(['on_budget' => true]);

        $this->postJson('/api/scheduled-transactions', [
            'account_id' => $account->id,
            'rta' => true,
            'amount' => 30000,
            'payee' => 'Lønn',
            'frequency' => 'monthly',
            'start_date' => now()->toDateString(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.rta', true)
            ->assertJsonPath('data.category_id', null);

        $this->postDue();

        $tx = $account->transactions()->firstOrFail();
        $this->assertTrue($tx->rta);
        $this->assertNull($tx->category_id);
        // En RTA-postering (lønn) skal ikke flagges som «mangler kategori».
        $this->assertSame(0, Transaction::query()->needsCategorization()->count());
    }

    // --- Planlagte overføringer ---

    public function test_planlagt_overforing_posteres_som_to_sammenkoblede_ben(): void
    {
        $from = Account::factory()->create(['on_budget' => true]);
        $to = Account::factory()->create(['on_budget' => true]);

        $this->postJson('/api/scheduled-transactions', [
            'account_id' => $from->id,
            'transfer_account_id' => $to->id,
            'amount' => 500,
            'frequency' => 'monthly',
            'start_date' => now()->toDateString(),
        ])->assertCreated();

        // Beløpet lagres signert negativt fra fra-kontoens ståsted.
        $schedule = ScheduledTransaction::firstOrFail();
        $this->assertEquals(-500, $schedule->amount);
        $this->assertSame($to->id, $schedule->transfer_account_id);

        $this->postDue();

        $fromLeg = $from->transactions()->firstOrFail();
        $toLeg = $to->transactions()->firstOrFail();
        $this->assertEquals(-500, $fromLeg->amount);
        $this->assertEquals(500, $toLeg->amount);
        $this->assertSame($toLeg->id, $fromLeg->transfer_id);
        $this->assertSame($schedule->id, $fromLeg->scheduled_transaction_id);
        // Begge ben er overføringer → ikke «mangler kategori».
        $this->assertSame(0, Transaction::query()->needsCategorization()->count());
    }

    public function test_planlagt_overforing_ut_av_budsjett_krever_kategori(): void
    {
        $from = Account::factory()->create(['on_budget' => true]);
        $tracking = Account::factory()->tracking()->create();

        $this->postJson('/api/scheduled-transactions', [
            'account_id' => $from->id,
            'transfer_account_id' => $tracking->id,
            'amount' => 300,
            'frequency' => 'monthly',
            'start_date' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrorFor('category_id');
    }

    public function test_planlagt_overforing_ut_av_budsjett_projiseres_i_kategorien(): void
    {
        $cat = Category::factory()->create();
        $from = Account::factory()->create(['on_budget' => true]);
        $tracking = Account::factory()->tracking()->create();
        $month = now()->format('Y-m');

        $this->postJson('/api/scheduled-transactions', [
            'account_id' => $from->id,
            'transfer_account_id' => $tracking->id,
            'category_id' => $cat->id,
            'amount' => 300,
            'frequency' => 'monthly',
            'start_date' => now()->addDay()->toDateString(), // i framtiden → projiseres, ikke postert
        ])->assertCreated();

        $groups = $this->getJson("/api/budget?month={$month}")->assertOk()->json('groups');
        $target = collect($groups)->flatMap(fn ($g) => $g['categories'])->firstWhere('id', $cat->id);

        // Kommende kategorisert forbruk (negativt) i kategorien.
        $this->assertEqualsWithDelta(-300, $target['upcoming'], 0.01);
    }

    public function test_planlagt_overforing_inn_til_budsjett_projiseres_som_rta(): void
    {
        $tracking = Account::factory()->tracking()->create();
        $budget = Account::factory()->create(['on_budget' => true]);
        $month = now()->format('Y-m');

        $this->postJson('/api/scheduled-transactions', [
            'account_id' => $tracking->id,
            'transfer_account_id' => $budget->id,
            'amount' => 300,
            'frequency' => 'monthly',
            'start_date' => now()->addDay()->toDateString(),
        ])->assertCreated();

        // Tilflyt inn til budsjettet projiseres som kommende RTA-inntekt.
        $this->getJson("/api/budget?month={$month}")
            ->assertOk()
            ->assertJsonPath('upcoming_income', 300);
    }
}
