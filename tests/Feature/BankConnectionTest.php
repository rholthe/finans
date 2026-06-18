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

    public function test_setter_visningsnavn_pa_tilkobling(): void
    {
        $connection = BankConnection::create([
            'institution_id' => 'SANDBOXFINANCE_SFIN0000', 'name' => 'Sandbox', 'consent_id' => 'r', 'status' => 'LN',
        ]);

        $this->putJson("/api/bank/connections/{$connection->id}", ['name' => '  DNB Privat  '])
            ->assertOk()
            ->assertJsonPath('data.name', 'DNB Privat');

        $this->assertDatabaseHas('bank_connections', ['id' => $connection->id, 'name' => 'DNB Privat']);
    }

    public function test_setter_og_nullstiller_visningsnavn_pa_bankkonto(): void
    {
        $connection = BankConnection::create([
            'institution_id' => 'SANDBOXFINANCE_SFIN0000', 'name' => 'Sandbox', 'consent_id' => 'r', 'status' => 'LN',
        ]);
        $bankAccount = $connection->bankAccounts()->create(['external_id' => 'acc-a', 'iban' => 'NO111']);

        $this->putJson("/api/bank/accounts/{$bankAccount->id}", ['name' => '  Brukskonto  '])
            ->assertOk()
            ->assertJsonPath('data.name', 'Brukskonto');
        $this->assertDatabaseHas('bank_accounts', ['id' => $bankAccount->id, 'name' => 'Brukskonto']);

        // Tomt navn nullstiller (fall tilbake på iban/external_id).
        $this->putJson("/api/bank/accounts/{$bankAccount->id}", ['name' => ''])
            ->assertOk()
            ->assertJsonPath('data.name', null);
        $this->assertDatabaseHas('bank_accounts', ['id' => $bankAccount->id, 'name' => null]);
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

    public function test_renew_starter_fornying_og_merker_okten(): void
    {
        $connection = BankConnection::create([
            'institution_id' => 'SANDBOXFINANCE_SFIN0000', 'name' => 'Sandbox', 'consent_id' => 'old', 'status' => 'EX',
        ]);

        $this->postJson("/api/bank/connections/{$connection->id}/renew")
            ->assertOk()
            ->assertJsonPath('link', 'https://example.test/link')
            ->assertSessionHas('bank_renew_connection_id', $connection->id);
    }

    public function test_callback_fornyer_og_beholder_kontokobling(): void
    {
        $account = Account::factory()->create();
        $connection = BankConnection::create([
            'institution_id' => 'SANDBOXFINANCE_SFIN0000', 'name' => 'Sandbox', 'consent_id' => 'old', 'status' => 'EX',
        ]);
        // Leverandøren gir nye konto-id-er ved fornying; vi re-mapper via IBAN.
        $bankAccount = $connection->bankAccounts()->create([
            'external_id' => 'acc-a', 'iban' => 'NO111', 'account_id' => $account->id,
        ]);

        $this->provider->consents['consent_x'] = [
            'status' => 'LN',
            'accounts' => ['acc-a-new'],
            'valid_until' => now()->addDays(90)->toIso8601String(),
        ];
        $this->provider->accountDetails['acc-a-new'] = ['iban' => 'NO111'];

        $this->withSession([
            'bank_ref' => 'tok',
            'bank_provider' => 'gocardless',
            'bank_consent_id' => 'consent_x',
            'bank_institution_id' => 'SANDBOXFINANCE_SFIN0000',
            'bank_renew_connection_id' => $connection->id,
        ])->get('/api/bank/callback?ref=tok')
            ->assertRedirect('/bank?status=renewed');

        // Kontokoblingen overlever: samme rad, ny external_id, beholdt account_id.
        $this->assertDatabaseHas('bank_accounts', [
            'id' => $bankAccount->id, 'external_id' => 'acc-a-new', 'account_id' => $account->id,
        ]);
        $this->assertDatabaseCount('bank_accounts', 1);
        $this->assertDatabaseHas('bank_connections', [
            'id' => $connection->id, 'consent_id' => 'consent_x', 'status' => 'LN',
        ]);
        $this->assertNotNull($connection->fresh()->valid_until);
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
