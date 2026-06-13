<?php

namespace App\Console\Commands;

use App\Services\ScheduledTransactionService;
use Illuminate\Console\Command;

class PostDueScheduledTransactions extends Command
{
    /**
     * @var string
     */
    protected $signature = 'transactions:post-due';

    /**
     * @var string
     */
    protected $description = 'Poster alle forfalte planlagte transaksjoner som faktiske transaksjoner';

    public function handle(ScheduledTransactionService $service): int
    {
        $created = $service->postDue();

        $this->info("Posterte {$created} forfalte transaksjon(er).");

        return self::SUCCESS;
    }
}
