<?php

namespace Tests\Feature;

use App\Jobs\SyncBankTransactionsJob;
use App\Models\Account;
use App\Models\BankConnection;
use App\Models\SyncEvent;
use App\Services\Bank\GoCardlessProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBankProvider;
use Tests\TestCase;

class BankConnectionTest extends TestCase
{
    use RefreshDatabase;

    private FakeBankProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.app_password_hash' => Hash::make('pw')]);
        $this->postJson('/api/login', ['password' => 'pw']);

        $this->provider = new FakeBankProvider;
        $this->provider->institutions = [
            ['id' => 'SANDBOXFINANCE_SFIN0000', 'name' => 'Sandbox Finance'],
        ];
        $this->app->instance(GoCardlessProvider::class, $this->provider);
    }

    public function test_connect_returnerer_lenke_og_lagrer_referanse(): void
    {
        $response = $this->postJson('/api/bank/connect', ['institution_id' => 'SANDBOXFINANCE_SFIN0000'])
            ->assertOk()
            ->assertJsonPath('link', 'https://example.test/link');

        $response->assertSessionHas('bank_ref');
    }

    public function test_callback_lagrer_bank_og_kontoer(): void
    {
        $this->provider->consents['req-x'] = ['status' => 'LN', 'accounts' => ['acc-a', 'acc-b']];
        $this->provider->accountDetails['acc-a'] = ['id' => 'acc-a', 'status' => 'READY', 'iban' => 'NO111'];
        $this->provider->accountDetails['acc-b'] = ['id' => 'acc-b', 'status' => 'READY', 'iban' => 'NO222'];

        $this->withSession([
            'bank_ref' => 'token123',
            'bank_provider' => 'gocardless',
            'bank_consent_id' => 'req-x',
            'bank_institution_id' => 'SANDBOXFINANCE_SFIN0000',
        ])->get('/api/bank/callback?ref=token123')
            ->assertRedirect('/bank?status=connected');

        $this->assertDatabaseHas('bank_connections', ['institution_id' => 'SANDBOXFINANCE_SFIN0000', 'name' => 'Sandbox Finance']);
        $this->assertDatabaseHas('bank_accounts', ['external_id' => 'acc-a', 'iban' => 'NO111']);
        $this->assertDatabaseCount('bank_accounts', 2);
    }

    public function test_callback_avviser_feil_referanse(): void
    {
        $this->withSession([
            'bank_ref' => 'token123',
            'bank_provider' => 'gocardless',
            'bank_consent_id' => 'req-x',
            'bank_institution_id' => 'SANDBOXFINANCE_SFIN0000',
        ])->get('/api/bank/callback?ref=feil')
            ->assertRedirect('/bank?status=error&reason=token');

        $this->assertDatabaseCount('bank_connections', 0);
    }

    public function test_kobler_bankkonto_til_budsjettkonto(): void
    {
        $account = Account::factory()->create();
        $connection = BankConnection::create([
            'institution_id' => 'SANDBOXFINANCE_SFIN0000', 'name' => 'Sandbox', 'consent_id' => 'r', 'status' => 'LN',
        ]);
        $bankAccount = $connection->bankAccounts()->create(['external_id' => 'acc-a']);

        $this->putJson("/api/bank/accounts/{$bankAccount->id}", ['account_id' => $account->id])
            ->assertOk()
            ->assertJsonPath('data.account_id', $account->id);

        $this->assertDatabaseHas('bank_accounts', ['id' => $bankAccount->id, 'account_id' => $account->id]);
    }

    public function test_sletter_tilkobling(): void
    {
        $connection = BankConnection::create([
            'institution_id' => 'SANDBOXFINANCE_SFIN0000', 'name' => 'Sandbox', 'consent_id' => 'r', 'status' => 'LN',
        ]);
        $connection->bankAccounts()->create(['external_id' => 'acc-a']);

        $this->deleteJson("/api/bank/connections/{$connection->id}")->assertNoContent();

        $this->assertDatabaseMissing('bank_connections', ['id' => $connection->id]);
        $this->assertDatabaseMissing('bank_accounts', ['external_id' => 'acc-a']);
    }

    public function test_manuell_synk_koeer_jobben_og_lager_processing_event(): void
    {
        Queue::fake();

        $this->postJson('/api/bank/sync')
            ->assertStatus(202)
            ->assertJsonPath('status', SyncEvent::STATUS_PROCESSING)
            ->assertJsonPath('finished', false);

        Queue::assertPushed(SyncBankTransactionsJob::class);
        $this->assertDatabaseHas('sync_events', ['status' => 'processing', 'trigger' => 'manual']);
    }

    public function test_synk_status_kan_polles(): void
    {
        $event = SyncEvent::create([
            'status' => SyncEvent::STATUS_NEW,
            'trigger' => 'manual',
            'imported_count' => 4,
        ]);

        $this->getJson("/api/bank/sync-status/{$event->id}")
            ->assertOk()
            ->assertJsonPath('finished', true)
            ->assertJsonPath('imported_count', 4);
    }

    public function test_connections_viser_rate_limit(): void
    {
        $connection = BankConnection::create([
            'institution_id' => 'SANDBOXFINANCE_SFIN0000', 'name' => 'Sandbox', 'consent_id' => 'r', 'status' => 'LN',
        ]);
        $connection->bankAccounts()->create([
            'external_id' => 'acc-a',
            'rate_limit' => 4,
            'rate_limit_remaining' => 2,
        ]);

        $this->getJson('/api/bank/connections')
            ->assertOk()
            ->assertJsonPath('data.0.accounts.0.rate_limit_remaining', 2);
    }
}
