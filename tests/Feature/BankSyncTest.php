<?php

namespace Tests\Feature;

use App\Mail\SyncReportMail;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Category;
use App\Models\Rule;
use App\Models\SyncEvent;
use App\Models\Transaction;
use App\Services\Bank\BankSyncService;
use App\Services\Bank\GoCardlessProvider;
use App\Services\Bank\NormalizedTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeBankProvider;
use Tests\TestCase;

class BankSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeBankProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gocardless.report_email' => 'rapport@example.test']);
        Mail::fake();

        $this->provider = new FakeBankProvider;
        $this->app->instance(GoCardlessProvider::class, $this->provider);
    }

    private function tx(string $id, float $amount, string $date = '2026-01-10', string $payee = 'Butikk', bool $booked = true): NormalizedTransaction
    {
        return new NormalizedTransaction(
            externalId: $id,
            date: $date,
            amount: $amount,
            currency: 'NOK',
            description: $payee,
            payee: $payee,
            memo: $payee,
            booked: $booked,
            raw: ['internalTransactionId' => $id],
        );
    }

    private function linkedAccount(): BankAccount
    {
        $account = Account::factory()->create(['on_budget' => true]);
        $connection = BankConnection::create([
            'institution_id' => 'SANDBOXFINANCE_SFIN0000',
            'name' => 'Sandbox',
            'consent_id' => 'req1',
            'status' => 'LN',
        ]);
        $this->provider->consents['req1'] = ['status' => 'LN', 'accounts' => ['acc1']];

        return $connection->bankAccounts()->create([
            'account_id' => $account->id,
            'external_id' => 'acc1',
            'iban' => 'NO1',
        ]);
    }

    private function sync(): SyncEvent
    {
        return app(BankSyncService::class)->sync();
    }

    public function test_importerer_nye_transaksjoner_paa_koblet_konto(): void
    {
        $bankAccount = $this->linkedAccount();
        $this->provider->transactions['acc1'] = [
            $this->tx('tx-1', -300),
            $this->tx('tx-2', 500),
        ];

        $event = $this->sync();

        $this->assertSame(SyncEvent::STATUS_NEW, $event->status);
        $this->assertSame(2, $event->imported_count);
        $this->assertDatabaseHas('transactions', [
            'account_id' => $bankAccount->account_id,
            'external_id' => 'tx-1',
            'amount' => -300,
        ]);
        Mail::assertSent(SyncReportMail::class);
    }

    public function test_synk_lagrer_samtykkeutlop_paa_tilkoblingen(): void
    {
        $bankAccount = $this->linkedAccount();
        $this->provider->consents['req1'] = [
            'status' => 'LN',
            'accounts' => ['acc1'],
            'valid_until' => now()->addDays(90)->toDateTimeString(),
        ];

        $this->sync();

        $this->assertNotNull($bankAccount->bankConnection->fresh()->valid_until);
    }

    public function test_regel_setter_payee_og_kategori_ved_import(): void
    {
        $bankAccount = $this->linkedAccount();
        $category = Category::factory()->create();
        Rule::factory()->create([
            'match_contains' => 'REMA',
            'set_payee' => 'Rema 1000',
            'category_id' => $category->id,
        ]);
        $this->provider->transactions['acc1'] = [$this->tx('tx-1', -250, '2026-01-10', 'KORTKJØP REMA 1000')];

        $this->sync();

        $this->assertDatabaseHas('transactions', [
            'external_id' => 'tx-1',
            'payee' => 'Rema 1000',
            'category_id' => $category->id,
            'bank_description' => 'KORTKJØP REMA 1000',
        ]);
    }

    public function test_to_kontoer_med_samme_external_id_importeres_begge(): void
    {
        // Sandbox returnerer identiske transaksjoner for hver konto – dedup må
        // være per konto, ikke global.
        $account1 = Account::factory()->create();
        $account2 = Account::factory()->create();
        $connection = BankConnection::create([
            'institution_id' => 'SANDBOXFINANCE_SFIN0000',
            'name' => 'Sandbox',
            'consent_id' => 'req1',
            'status' => 'LN',
        ]);
        $this->provider->consents['req1'] = ['status' => 'LN', 'accounts' => ['acc1', 'acc2']];
        $connection->bankAccounts()->create(['account_id' => $account1->id, 'external_id' => 'acc1']);
        $connection->bankAccounts()->create(['account_id' => $account2->id, 'external_id' => 'acc2']);

        $this->provider->transactions['acc1'] = [$this->tx('shared-1', -300), $this->tx('shared-2', 500)];
        $this->provider->transactions['acc2'] = [$this->tx('shared-1', -300), $this->tx('shared-2', 500)];

        $event = $this->sync();

        $this->assertSame(4, $event->imported_count);
        $this->assertDatabaseHas('transactions', ['account_id' => $account1->id, 'external_id' => 'shared-1']);
        $this->assertDatabaseHas('transactions', ['account_id' => $account2->id, 'external_id' => 'shared-1']);
    }

    public function test_dedupliserer_allerede_importerte(): void
    {
        $bankAccount = $this->linkedAccount();
        Transaction::create([
            'account_id' => $bankAccount->account_id,
            'external_id' => 'tx-1',
            'date' => '2026-01-10',
            'amount' => -300,
        ]);
        $this->provider->transactions['acc1'] = [$this->tx('tx-1', -300), $this->tx('tx-2', 500)];

        $event = $this->sync();

        $this->assertSame(1, $event->imported_count); // kun tx-2
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_reservert_lagres_som_pending_og_ikke_klarert(): void
    {
        $bankAccount = $this->linkedAccount();
        $this->provider->transactions['acc1'] = [
            $this->tx('booked-1', -300, booked: true),
            $this->tx('pend-1', -50, booked: false),
        ];

        $event = $this->sync();

        // Kun bokførte teller som «nye/importerte».
        $this->assertSame(1, $event->imported_count);
        $this->assertDatabaseHas('transactions', [
            'account_id' => $bankAccount->account_id,
            'external_id' => 'booked-1',
            'cleared' => true,
            'pending' => false,
        ]);
        $this->assertDatabaseHas('transactions', [
            'account_id' => $bankAccount->account_id,
            'external_id' => 'pend-1',
            'cleared' => false,
            'pending' => true,
        ]);
    }

    public function test_reservert_byttes_ut_naar_den_bokfores(): void
    {
        $bankAccount = $this->linkedAccount();

        // Synk 1: posten er reservert (egen, ustabil id).
        $this->provider->transactions['acc1'] = [$this->tx('pend-tmp', -120, booked: false)];
        $this->sync();
        $this->assertDatabaseCount('transactions', 1);

        // Synk 2: banken har bokført den (ny id), ingen reserverte igjen.
        $this->provider->transactions['acc1'] = [$this->tx('booked-real', -120, booked: true)];
        $this->sync();

        // Den reserverte raden er borte, kun den bokførte står igjen.
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseMissing('transactions', ['external_id' => 'pend-tmp']);
        $this->assertDatabaseHas('transactions', [
            'external_id' => 'booked-real',
            'cleared' => true,
            'pending' => false,
        ]);
    }

    public function test_reservert_dupliseres_ikke_over_flere_synker(): void
    {
        $this->linkedAccount();
        $this->provider->transactions['acc1'] = [$this->tx('pend-1', -75, booked: false)];

        $this->sync();
        $this->sync();
        $this->sync();

        // Reserverte erstattes hver synk – ikke akkumuleres.
        $this->assertSame(1, Transaction::where('pending', true)->count());
    }

    public function test_laast_reservert_rad_bevares_ved_synk(): void
    {
        $bankAccount = $this->linkedAccount();
        // En reservert rad brukeren har redigert (locked) skal ikke fjernes.
        Transaction::create([
            'account_id' => $bankAccount->account_id,
            'external_id' => 'pend-locked',
            'date' => '2026-01-10',
            'amount' => -200,
            'cleared' => false,
            'pending' => true,
            'locked' => true,
        ]);
        $this->provider->transactions['acc1'] = [];

        $this->sync();

        $this->assertDatabaseHas('transactions', ['external_id' => 'pend-locked', 'pending' => true]);
    }

    public function test_rate_limit_429_markerer_konto_ikke_synkbar(): void
    {
        $bankAccount = $this->linkedAccount();
        $this->provider->rateLimited['acc1'] = null; // 429 uten Retry-After
        $this->provider->transactions['acc1'] = [$this->tx('tx-1', -300)];

        $event = $this->sync();

        $this->assertSame(SyncEvent::STATUS_WITH_ERRORS, $event->status);
        $this->assertSame(0, $event->imported_count);
        $this->assertDatabaseCount('transactions', 0);

        $bankAccount->refresh();
        $this->assertSame(0, $bankAccount->rate_limit_remaining);
        $this->assertTrue($bankAccount->rate_limit_reset_at->isFuture());
        $this->assertFalse($bankAccount->isSyncable());
    }

    public function test_rate_limit_429_respekterer_retry_after(): void
    {
        $bankAccount = $this->linkedAccount();
        $retryAt = now()->addMinutes(15)->startOfSecond();
        $this->provider->rateLimited['acc1'] = $retryAt->toImmutable();

        $this->sync();

        $bankAccount->refresh();
        $this->assertEquals(
            $retryAt->toDateTimeString(),
            $bankAccount->rate_limit_reset_at->toDateTimeString(),
        );
    }

    public function test_rate_limited_konto_hoppes_over_paa_neste_synk(): void
    {
        $bankAccount = $this->linkedAccount();
        // Kontoen er allerede rate-limited fra en tidligere synk.
        $bankAccount->update([
            'rate_limit_remaining' => 0,
            'rate_limit_reset_at' => now()->addHour(),
        ]);
        $this->provider->transactions['acc1'] = [$this->tx('tx-1', -300)];

        $event = $this->sync();

        // Hoppet over av isSyncable()-gatingen før noe API-kall – ingen import.
        $this->assertSame(SyncEvent::STATUS_WITH_ERRORS, $event->status);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_vellykket_synk_nullstiller_utdatert_429_markering(): void
    {
        $bankAccount = $this->linkedAccount();
        // Utdatert 429-markering: kvoten er for lengst nullstilt (reset i fortid),
        // men remaining=0 henger igjen siden leverandøren (EB) ikke sender tall.
        $bankAccount->update([
            'rate_limit_remaining' => 0,
            'rate_limit_reset_at' => now()->subDay(),
        ]);
        $this->provider->transactions['acc1'] = [$this->tx('tx-1', -300)];

        $event = $this->sync();

        $this->assertSame(SyncEvent::STATUS_NEW, $event->status);
        $bankAccount->refresh();
        $this->assertNull($bankAccount->rate_limit_remaining);
        $this->assertNull($bankAccount->rate_limit_reset_at);
        $this->assertTrue($bankAccount->isSyncable());
    }

    public function test_hopper_over_konto_uten_kobling(): void
    {
        $connection = BankConnection::create([
            'institution_id' => 'SANDBOXFINANCE_SFIN0000',
            'name' => 'Sandbox',
            'consent_id' => 'req1',
            'status' => 'LN',
        ]);
        $this->provider->consents['req1'] = ['status' => 'LN', 'accounts' => ['acc1']];
        $connection->bankAccounts()->create(['external_id' => 'acc1', 'account_id' => null]);
        $this->provider->transactions['acc1'] = [$this->tx('tx-1', -300)];

        $event = $this->sync();

        $this->assertSame(0, $event->imported_count);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_status_no_new_naar_ingenting_nytt(): void
    {
        $this->linkedAccount();
        $this->provider->transactions['acc1'] = [];

        $event = $this->sync();

        $this->assertSame(SyncEvent::STATUS_NO_NEW, $event->status);
        Mail::assertSent(SyncReportMail::class); // e-post sendes også ved ingen nye
    }

    public function test_ikke_linket_bank_gir_status_med_feil(): void
    {
        $this->linkedAccount();
        $this->provider->consents['req1'] = ['status' => 'EX', 'accounts' => ['acc1']]; // utløpt
        $this->provider->transactions['acc1'] = [$this->tx('tx-1', -300)];

        $event = $this->sync();

        $this->assertSame(SyncEvent::STATUS_WITH_ERRORS, $event->status);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_regel_med_rta_mal_markerer_posteringen_som_rta(): void
    {
        $this->linkedAccount();
        Rule::create(['match_contains' => 'LØNN', 'target_type' => 'rta']);
        $this->provider->transactions['acc1'] = [$this->tx('inc-1', 25000, '2026-01-10', 'LØNN FIRMA')];

        $this->sync();

        $tx = Transaction::where('external_id', 'inc-1')->firstOrFail();
        $this->assertTrue($tx->rta);
        $this->assertNull($tx->category_id);
        $this->assertSame(0, Transaction::query()->needsCategorization()->count());
    }

    public function test_regel_med_overforing_lager_to_sammenkoblede_ben(): void
    {
        $bankAccount = $this->linkedAccount(); // budsjettkonto
        $sparing = Account::factory()->tracking()->create(['name' => 'Sparing']); // overvåket, ikke synket
        $category = Category::factory()->create();
        Rule::create([
            'match_contains' => 'SPARING',
            'target_type' => 'transfer',
            'transfer_account_id' => $sparing->id,
            'category_id' => $category->id,
        ]);
        $this->provider->transactions['acc1'] = [$this->tx('sav-1', -2000, '2026-01-10', 'FAST SPARING')];

        $this->sync();

        $bankLeg = Transaction::where('external_id', 'sav-1')->firstOrFail();
        $this->assertSame($bankAccount->account_id, $bankLeg->account_id);
        $this->assertEquals(-2000, $bankLeg->amount);
        // Budsjett → overvåket: bank-benet er kategorisert.
        $this->assertSame($category->id, $bankLeg->category_id);
        $this->assertNotNull($bankLeg->transfer_id);

        $opposite = Transaction::find($bankLeg->transfer_id);
        $this->assertSame($sparing->id, $opposite->account_id);
        $this->assertEquals(2000, $opposite->amount);

        // Re-synk dupliserer ikke (bank-benet beholder external_id for dedup).
        $this->sync();
        $this->assertSame(1, Transaction::where('external_id', 'sav-1')->count());
        $this->assertDatabaseCount('transactions', 2);
    }
}
