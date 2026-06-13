<?php

namespace App\Jobs;

use App\Models\SyncEvent;
use App\Services\Bank\BankSyncService;
use App\Support\AppSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Køet banksynk. WithoutOverlapping sikrer at aldri to synker kjører samtidig
 * (manuell og nattlig), og retries dekker midlertidige API-feil.
 */
class SyncBankTransactionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 600;

    /**
     * @param  int|null  $syncEventId  Forhåndsopprettet processing-event (manuell synk), ellers opprettes en.
     */
    public function __construct(
        public ?int $syncEventId = null,
        public string $trigger = 'auto',
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('bank-sync'))->releaseAfter(60)->expireAfter(900)];
    }

    public function handle(BankSyncService $service): void
    {
        $days = $this->trigger === 'auto'
            ? AppSettings::autoSyncDays()
            : AppSettings::manualSyncDays();

        $event = $this->syncEventId ? SyncEvent::find($this->syncEventId) : null;

        if ($event) {
            $service->runInto($event, $days);
        } else {
            $service->sync($days, $this->trigger);
        }
    }
}
