<?php

namespace Tests\Feature;

use App\Mail\SyncReportMail;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\SyncEvent;
use App\Models\Transaction;
use App\Services\Bank\BankDataProvider;
use App\Services\Bank\BankSyncService;
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
        config(['gocardless.report_email' => 'rapport@example.test', 'gocardless.sync_days' => 90]);
        Mail::fake();

        $this->provider = new FakeBankProvider;
        $this->app->instance(BankDataProvider::class, $this->provider);
    }

    private function tx(string $id, float $amount, string $date = '2026-01-10', string $payee = 'Butikk'): NormalizedTransaction
    {
        return new NormalizedTransaction($id, $date, $amount, 'NOK', $payee, $payee, ['internalTransactionId' => $id]);
    }

    private function linkedAccount(): BankAccount
    {
        $account = Account::factory()->create(['on_budget' => true]);
        $connection = BankConnection::create([
            'institution_id' => 'SANDBOXFINANCE_SFIN0000',
            'name' => 'Sandbox',
            'requisition_id' => 'req1',
            'status' => 'LN',
        ]);
        $this->provider->requisitions['req1'] = ['status' => 'LN', 'accounts' => ['acc1']];

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

    public function test_hopper_over_konto_uten_kobling(): void
    {
        $connection = BankConnection::create([
            'institution_id' => 'SANDBOXFINANCE_SFIN0000',
            'name' => 'Sandbox',
            'requisition_id' => 'req1',
            'status' => 'LN',
        ]);
        $this->provider->requisitions['req1'] = ['status' => 'LN', 'accounts' => ['acc1']];
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
        $this->provider->requisitions['req1'] = ['status' => 'EX', 'accounts' => ['acc1']]; // utløpt
        $this->provider->transactions['acc1'] = [$this->tx('tx-1', -300)];

        $event = $this->sync();

        $this->assertSame(SyncEvent::STATUS_WITH_ERRORS, $event->status);
        $this->assertDatabaseCount('transactions', 0);
    }
}
