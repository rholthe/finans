<?php

namespace Tests\Feature;

use App\Services\Bank\EnableBankingProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnableBankingProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Generer et ekte RSA-nøkkelpar slik at JWT-signeringen virker.
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $pem);

        config([
            'enablebanking.base_uri' => 'https://eb.test',
            'enablebanking.application_id' => 'app-123',
            'enablebanking.private_key' => $pem,
            'enablebanking.redirect_uri' => 'https://app.test/api/bank/callback',
            'enablebanking.country' => 'NO',
        ]);
    }

    public function test_institusjoner_mappes_til_id_og_navn(): void
    {
        Http::fake([
            'eb.test/aspsps*' => Http::response(['aspsps' => [
                ['name' => 'DNB', 'country' => 'NO'],
                ['name' => 'Sparebank 1', 'country' => 'NO'],
            ]]),
        ]);

        $result = (new EnableBankingProvider)->getInstitutions('NO');

        $this->assertCount(2, $result);
        $this->assertSame('DNB', $result[0]['id']);
        $this->assertSame('DNB', $result[0]['name']);
    }

    public function test_create_consent_returnerer_lenke(): void
    {
        Http::fake([
            'eb.test/auth' => Http::response(['url' => 'https://eb.test/authorize/abc']),
        ]);

        $consent = (new EnableBankingProvider)->createConsent('DNB', 'ref-1');

        $this->assertSame('https://eb.test/authorize/abc', $consent->link);
        $this->assertFalse($consent->linked);
    }

    public function test_complete_consent_oppretter_session_med_kontoer(): void
    {
        Http::fake([
            'eb.test/sessions' => Http::response([
                'session_id' => 'sess-9',
                'status' => 'AUTHORIZED',
                'accounts' => [['uid' => 'acc-1'], ['uid' => 'acc-2']],
            ]),
        ]);

        $consent = (new EnableBankingProvider)->completeConsent(['code' => 'auth-code'], null);

        $this->assertSame('sess-9', $consent->id);
        $this->assertTrue($consent->linked);
        $this->assertSame(['acc-1', 'acc-2'], $consent->accountIds);
    }

    public function test_transaksjoner_normaliseres_med_fortegn_fra_indikator(): void
    {
        Http::fake([
            'eb.test/accounts/acc-1/transactions*' => Http::response(['transactions' => [
                [
                    'entry_reference' => 'tx-1',
                    'booking_date' => '2026-01-10',
                    'transaction_amount' => ['amount' => '250.50', 'currency' => 'NOK'],
                    'credit_debit_indicator' => 'DBIT',
                    'creditor' => ['name' => 'REMA 1000'],
                    'remittance_information' => ['Varekjøp'],
                ],
            ]]),
        ]);

        $result = (new EnableBankingProvider)->getTransactions('acc-1', 'DNB', '2026-01-01');

        $this->assertCount(1, $result);
        $this->assertSame('tx-1', $result[0]->externalId);
        $this->assertSame(-250.5, $result[0]->amount); // DBIT → negativt
        $this->assertSame('2026-01-10', $result[0]->date);
        $this->assertStringContainsString('REMA 1000', $result[0]->payee);
    }

    public function test_callback_referanse_leses_fra_state(): void
    {
        $provider = new EnableBankingProvider;

        $this->assertSame('ref-1', $provider->callbackReference(['state' => 'ref-1', 'code' => 'x']));
        $this->assertNull($provider->callbackReference(['code' => 'x']));
    }
}
