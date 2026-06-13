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
        // Bankleverandør bak abstraksjon: bytt klasse her for ny aggregator.
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
