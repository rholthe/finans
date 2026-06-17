<?php

namespace Tests\Feature;

use App\Mail\ExpiringConsentMail;
use App\Models\BankConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckBankConsentExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gocardless.report_email' => 'meg@test.no']);
    }

    private function connection(string $name, ?string $validUntil, ?string $notifiedAt = null): BankConnection
    {
        return BankConnection::create([
            'institution_id' => 'SANDBOXFINANCE_SFIN0000',
            'name' => $name,
            'consent_id' => 'c-'.$name,
            'status' => 'LN',
            'valid_until' => $validUntil,
            'expiry_notified_at' => $notifiedAt,
        ]);
    }

    public function test_varsler_om_tilkobling_naer_utlop_og_merker_varslet(): void
    {
        Mail::fake();

        $soon = $this->connection('Snart', now()->addDays(3)->toDateTimeString());
        $far = $this->connection('Langt', now()->addDays(60)->toDateTimeString());

        $this->artisan('bank:check-expiry')->assertSuccessful();

        Mail::assertSent(ExpiringConsentMail::class, function (ExpiringConsentMail $mail) use ($soon, $far) {
            return $mail->connections->contains('id', $soon->id)
                && ! $mail->connections->contains('id', $far->id);
        });

        $this->assertNotNull($soon->fresh()->expiry_notified_at);
    }

    public function test_varsler_ikke_paa_nytt_naar_allerede_varslet(): void
    {
        Mail::fake();

        $this->connection('Varslet', now()->addDays(3)->toDateTimeString(), now()->toDateTimeString());

        $this->artisan('bank:check-expiry')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_varsler_ikke_om_allerede_utlopt(): void
    {
        Mail::fake();

        $this->connection('Utløpt', now()->subDay()->toDateTimeString());

        $this->artisan('bank:check-expiry')->assertSuccessful();

        Mail::assertNothingSent();
    }
}
