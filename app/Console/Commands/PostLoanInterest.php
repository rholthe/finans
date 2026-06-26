<?php

namespace App\Console\Commands;

use App\Services\LoanService;
use Illuminate\Console\Command;

class PostLoanInterest extends Command
{
    /**
     * @var string
     */
    protected $signature = 'loans:post-interest';

    /**
     * @var string
     */
    protected $description = 'Poster månedlig rente på lånekontoer med effektiv rente satt';

    public function handle(LoanService $service): int
    {
        $posted = $service->postMonthlyInterest();

        $this->info("Posterte rente på {$posted} lånekonto(er).");

        return self::SUCCESS;
    }
}
