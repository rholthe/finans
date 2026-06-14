<?php

namespace App\Providers;

use App\Services\Bank\BankDataProvider;
use App\Services\Bank\GoCardlessProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bankleverandør velges per tilkobling via BankProviderRegistry. Et
        // standardbind beholdes for kode som måtte be om grensesnittet direkte;
        // nye leverandører legges til i registeret, ikke her.
        $this->app->bind(BankDataProvider::class, GoCardlessProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
