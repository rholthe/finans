<?php

namespace App\Console\Commands;

use App\Services\Bank\BankSyncService;
use Illuminate\Console\Command;

class SyncBankTransactions extends Command
{
    /**
     * @var string
     */
    protected $signature = 'bank:sync';

    /**
     * @var string
     */
    protected $description = 'Hent og importer nye banktransaksjoner fra alle tilkoblede banker';

    public function handle(BankSyncService $service): int
    {
        $event = $service->sync();

        $this->info("Synk fullført ({$event->status}): {$event->imported_count} nye transaksjon(er).");

        return self::SUCCESS;
    }
}
