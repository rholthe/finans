<?php

namespace App\Console\Commands;

use App\Services\Bank\BankSyncService;
use App\Support\AppSettings;
use Illuminate\Console\Command;

class SyncBankTransactions extends Command
{
    /**
     * @var string
     */
    protected $signature = 'bank:sync {--manual : Bruk antall dager for manuell synk i stedet for auto}';

    /**
     * @var string
     */
    protected $description = 'Hent og importer nye banktransaksjoner fra alle tilkoblede banker (synkront, for CLI/debugging)';

    public function handle(BankSyncService $service): int
    {
        $trigger = $this->option('manual') ? 'manual' : 'auto';
        $days = $trigger === 'manual' ? AppSettings::manualSyncDays() : AppSettings::autoSyncDays();

        $event = $service->sync($days, $trigger);

        $this->info("Synk fullført ({$event->status}): {$event->imported_count} nye transaksjon(er), {$days} dager.");

        return self::SUCCESS;
    }
}
