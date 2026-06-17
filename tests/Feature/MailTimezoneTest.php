<?php

namespace Tests\Feature;

use App\Mail\ExpiringConsentMail;
use App\Mail\SyncReportMail;
use App\Models\BankConnection;
use App\Models\SyncEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class MailTimezoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.display_timezone' => 'Europe/Oslo']);
    }

    public function test_synk_rapport_konverterer_utc_til_lokal_tid(): void
    {
        $event = new SyncEvent([
            'status' => SyncEvent::STATUS_NEW,
            'trigger' => 'auto',
            'imported_count' => 2,
            'days_synced' => 5,
            'report' => [],
        ]);
        // 19:30 UTC i juni = 21:30 i Oslo (CEST, UTC+2).
        $event->created_at = CarbonImmutable::parse('2026-06-17 19:30:00', 'UTC');

        $html = (new SyncReportMail($event))->render();

        $this->assertStringContainsString('21:30', $html);
        $this->assertStringContainsString('CEST', $html);
        $this->assertStringNotContainsString('19:30', $html);
    }

    public function test_utlopsvarsel_konverterer_dato_til_lokal_tid(): void
    {
        $connection = new BankConnection(['name' => 'Testbank', 'status' => 'LN']);
        // 23:30 UTC = 01:30 neste dag i Oslo → datoen skal rulle over til 18.06.
        $connection->valid_until = CarbonImmutable::parse('2026-06-17 23:30:00', 'UTC');

        $html = (new ExpiringConsentMail(new Collection([$connection])))->render();

        $this->assertStringContainsString('18.06.2026', $html);
    }
}
