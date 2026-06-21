<?php

namespace Tests\Feature;

use App\Services\Bank\BankRateLimitException;
use App\Services\Bank\EnableBankingProvider;
use Illuminate\Support\Facades\Artisan;
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

    public function test_raw_institusjoner_beholder_full_metadata(): void
    {
        Http::fake([
            'eb.test/aspsps*' => Http::response(['aspsps' => [
                ['name' => 'Bulder', 'country' => 'NO', 'required_psu_headers' => ['psu-ip-address'], 'maximum_consent_validity' => 7776000],
            ]]),
        ]);

        $result = (new EnableBankingProvider)->getInstitutionsRaw('NO');

        $this->assertCount(1, $result);
        $this->assertSame(['psu-ip-address'], $result[0]['required_psu_headers']);
        $this->assertSame(7776000, $result[0]['maximum_consent_validity']);
    }

    public function test_aspsp_metadata_kommando_dumper_full_metadata(): void
    {
        Http::fake([
            'eb.test/aspsps*' => Http::response(['aspsps' => [
                ['name' => 'Bulder', 'country' => 'NO', 'required_psu_headers' => ['psu-ip-address']],
                ['name' => 'DNB', 'country' => 'NO'],
            ]]),
        ]);

        $exit = Artisan::call('bank:aspsp-metadata', ['--filter' => 'bulder']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Bulder', $output);
        $this->assertStringContainsString('required_psu_headers', $output);
        $this->assertStringNotContainsString('DNB', $output);
    }

    public function test_psu_ip_fra_config_sendes_som_header_ved_uovervaaket_synk(): void
    {
        config(['enablebanking.psu_ip' => '203.0.113.7', 'enablebanking.psu_user_agent' => 'Finans/1.0']);

        Http::fake(['eb.test/accounts/*/transactions*' => Http::response(['transactions' => []])]);

        (new EnableBankingProvider)->getTransactions('acc-1', 'Bulder', '2026-01-01');

        Http::assertSent(fn ($request): bool => $request->hasHeader('psu-ip-address', '203.0.113.7')
            && $request->hasHeader('psu-user-agent', 'Finans/1.0'));
    }

    public function test_set_psu_context_overstyrer_config_fallback(): void
    {
        config(['enablebanking.psu_ip' => '203.0.113.7']);

        Http::fake(['eb.test/accounts/*/transactions*' => Http::response(['transactions' => []])]);

        $provider = new EnableBankingProvider;
        $provider->setPsuContext('198.51.100.42', 'Mozilla/5.0');
        $provider->getTransactions('acc-1', 'Bulder', '2026-01-01');

        Http::assertSent(fn ($request): bool => $request->hasHeader('psu-ip-address', '198.51.100.42')
            && $request->hasHeader('psu-user-agent', 'Mozilla/5.0'));
    }

    public function test_ingen_psu_ip_header_naar_ikke_konfigurert(): void
    {
        config(['enablebanking.psu_ip' => null]);

        Http::fake(['eb.test/accounts/*/transactions*' => Http::response(['transactions' => []])]);

        (new EnableBankingProvider)->getTransactions('acc-1', 'Bulder', '2026-01-01');

        Http::assertSent(fn ($request): bool => ! $request->hasHeader('psu-ip-address'));
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

    public function test_get_consent_taaler_kontoer_som_uid_strenger(): void
    {
        // GET /sessions/{id} returnerer accounts som rene uid-strenger, ikke objekter.
        Http::fake([
            'eb.test/sessions/sess-9' => Http::response([
                'status' => 'AUTHORIZED',
                'accounts' => ['acc-1', 'acc-2'],
            ]),
        ]);

        $consent = (new EnableBankingProvider)->getConsent('sess-9');

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

    public function test_payee_hentes_fra_overforing_linje_naar_strukturert_navn_mangler(): void
    {
        // DNB-mønster: ingen strukturert kreditor/debitor, motpartsnavnet ligger i
        // «Overføring Innland, <navn>»-linja, og referanselinja er kolonnepadet.
        Http::fake([
            'eb.test/accounts/acc-1/transactions*' => Http::response(['transactions' => [
                [
                    'transaction_id' => 'elvia-1',
                    'booking_date' => '2026-06-17',
                    'transaction_amount' => ['amount' => '478.77', 'currency' => 'NOK'],
                    'credit_debit_indicator' => 'CRDT',
                    'creditor' => null,
                    'debtor' => null,
                    'remittance_information' => [
                        'Faktureringsavtaleid: 85570          Fakturanr: 39259312          Leveringsadresse: Åsfaret 7A',
                        'Overføring Innland, Elvia As',
                    ],
                ],
            ]]),
        ]);

        $result = (new EnableBankingProvider)->getTransactions('acc-1', 'DNB', '2026-06-01');

        $this->assertCount(1, $result);
        // Motpart trukket ut som payee (ikke referansetallene).
        $this->assertSame('Elvia As', $result[0]->payee);
        // Full info (regelgrunnlag) beholder alt, men uten kolonnepadding.
        $this->assertStringContainsString('Elvia As', $result[0]->description);
        $this->assertStringContainsString('Fakturanr: 39259312', $result[0]->description);
        $this->assertStringNotContainsString('  ', $result[0]->description);
    }

    public function test_reservert_transaksjon_markeres_ikke_bokfoert(): void
    {
        Http::fake([
            'eb.test/accounts/acc-1/transactions*' => Http::response(['transactions' => [
                ['entry_reference' => 'b-1', 'booking_date' => '2026-01-10', 'transaction_amount' => ['amount' => '100', 'currency' => 'NOK'], 'credit_debit_indicator' => 'CRDT', 'status' => 'BOOK'],
                ['entry_reference' => 'p-1', 'booking_date' => '2026-01-10', 'transaction_amount' => ['amount' => '50', 'currency' => 'NOK'], 'credit_debit_indicator' => 'DBIT', 'status' => 'PEND'],
            ]]),
        ]);

        $result = (new EnableBankingProvider)->getTransactions('acc-1', 'DNB', '2026-01-01');

        $this->assertTrue($result[0]->booked);
        $this->assertFalse($result[1]->booked);
    }

    public function test_429_kaster_rate_limit_exception(): void
    {
        Http::fake([
            'eb.test/accounts/acc-1/transactions*' => Http::response(['error' => 'too many'], 429),
        ]);

        $this->expectException(BankRateLimitException::class);

        (new EnableBankingProvider)->getTransactions('acc-1', 'DNB', '2026-01-01');
    }

    public function test_429_leser_retry_after_i_sekunder(): void
    {
        Http::fake([
            'eb.test/accounts/acc-1/transactions*' => Http::response(['error' => 'too many'], 429, ['Retry-After' => '120']),
        ]);

        try {
            (new EnableBankingProvider)->getTransactions('acc-1', 'DNB', '2026-01-01');
            $this->fail('Forventet BankRateLimitException.');
        } catch (BankRateLimitException $e) {
            $this->assertNotNull($e->retryAt);
            $this->assertEqualsWithDelta(120, now()->diffInSeconds($e->retryAt, false), 5);
        }
    }

    public function test_callback_referanse_leses_fra_state(): void
    {
        $provider = new EnableBankingProvider;

        $this->assertSame('ref-1', $provider->callbackReference(['state' => 'ref-1', 'code' => 'x']));
        $this->assertNull($provider->callbackReference(['code' => 'x']));
    }
}
