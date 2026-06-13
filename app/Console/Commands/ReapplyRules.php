<?php

namespace App\Console\Commands;

use App\Services\Rules\ReapplyRules as ReapplyRulesService;
use Illuminate\Console\Command;

class ReapplyRules extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rules:reapply';

    /**
     * @var string
     */
    protected $description = 'Kjør regelmotoren på nytt mot alle bank-importerte transaksjoner';

    public function handle(ReapplyRulesService $service): int
    {
        $changed = $service->run();

        $this->info("Oppdaterte {$changed} transaksjon(er).");

        return self::SUCCESS;
    }
}
