<?php

namespace Tests\Feature;

use App\Services\Bank\GoCardlessProvider;
use App\Services\Bank\Mapping\SandboxBankMapping;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoCardlessProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'gocardless.base_uri' => 'https://gcl.test/api/v2',
            'gocardless.secret_id' => 'secret-id',
            'gocardless.secret_key' => 'secret-key',
            'gocardless.redirect_uri' => 'https://app.test/api/bank/callback',
            'gocardless.mappings' => [
                'SANDBOXFINANCE_SFIN0000' => SandboxBankMapping::class,
            ],
        ]);
    }

    private function fakeBooked(array $booked, array $headers = []): void
    {
        Http::fake(function ($request) use ($booked, $headers) {
            $url = $request->url();

            if (str_contains($url, '/token/new/')) {
                return Http::response(['access' => 'tok', 'access_expires' => 3600, 'refresh' => 'ref', 'refresh_expires' => 7200]);
            }
            if (str_contains($url, '/transactions/')) {
                return Http::response(['transactions' => ['booked' => $booked]], 200, $headers);
            }

            return Http::response([], 200);
        });
    }

    public function test_normaliserer_transaksjon_med_datofallback_og_payee_fra_mapping(): void
    {
        $this->travelTo('2026-01-15');
        $this->fakeBooked([
            [
                'internalTransactionId' => 'tx-1',
                'bookingDate' => '2026-02-01', // i framtiden → skal falle tilbake
                'valueDate' => '2026-01-10',
                'transactionAmount' => ['amount' => '-250.50', 'currency' => 'NOK'],
                'remittanceInformationUnstructured' => 'REMA 1000',
            ],
        ]);

        $provider = new GoCardlessProvider;
        $result = $provider->getTransactions('acc-1', 'SANDBOXFINANCE_SFIN0000', '2026-01-01');

        $this->assertCount(1, $result);
        $tx = $result[0];
        $this->assertSame('tx-1', $tx->externalId);
        $this->assertSame('2026-01-10', $tx->date); // valueDate, ikke framtidig bookingDate
        $this->assertSame(-250.5, $tx->amount);
        $this->assertSame('NOK', $tx->currency);
        $this->assertSame('REMA 1000', $tx->payee);
    }

    public function test_fanger_rate_limit_fra_headere(): void
    {
        $this->fakeBooked([], [
            'X-RateLimit-Account-Success-Limit' => '4',
            'X-RateLimit-Account-Success-Remaining' => '0',
            'X-RateLimit-Account-Success-Reset' => '3600',
        ]);

        $provider = new GoCardlessProvider;
        $provider->getTransactions('acc-1', 'SANDBOXFINANCE_SFIN0000', '2026-01-01');

        $rateLimit = $provider->lastRateLimit();
        $this->assertSame(4, $rateLimit['limit']);
        $this->assertSame(0, $rateLimit['remaining']);
        $this->assertNotNull($rateLimit['reset_at']);
    }

    public function test_henter_normalisert_banksaldo(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/token/new/')) {
                return Http::response(['access' => 'tok', 'access_expires' => 3600, 'refresh' => 'ref', 'refresh_expires' => 7200]);
            }
            if (str_contains($request->url(), '/balances/')) {
                return Http::response(['balances' => [
                    ['balanceAmount' => ['amount' => '1000.00', 'currency' => 'NOK'], 'balanceType' => 'closingBooked'],
                    ['balanceAmount' => ['amount' => '950.00', 'currency' => 'NOK'], 'balanceType' => 'interimAvailable'],
                ]]);
            }

            return Http::response([], 200);
        });

        $provider = new GoCardlessProvider;
        $balance = $provider->getBalances('acc-1');

        $this->assertSame(1000.0, $balance->booked);
        $this->assertSame(950.0, $balance->available);
        $this->assertSame('NOK', $balance->currency);
    }

    public function test_henter_token_kun_en_gang_for_flere_kall(): void
    {
        $this->fakeBooked([]);

        $provider = new GoCardlessProvider;
        $provider->getInstitutions('NO');
        $provider->getTransactions('acc-1', 'SANDBOXFINANCE_SFIN0000', '2026-01-01');

        // Kun ett /token/new/-kall selv om vi gjorde to API-kall (token caches).
        Http::assertSentCount(3); // token/new + institutions + transactions
        Http::assertSent(fn ($request) => str_contains($request->url(), '/token/new/'));
    }
}
