<?php

use App\Http\Middleware\AssistantToken;
use App\Http\Middleware\Authenticated;
use App\Http\Middleware\EnsureScheduledTransactionsPosted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Assistent-API: egen nøkkel, bare GET, uten web-gruppen (ingen session/CSRF)
        then: function (): void {
            Route::prefix('api/assistent')
                ->middleware([AssistantToken::class, SubstituteBindings::class, EnsureScheduledTransactionsPosted::class])
                ->group(base_path('routes/assistent.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Appen kjører bak Caddy (reverse proxy) i Docker - stol på X-Forwarded-*
        // headere derfra slik at genererte URL-er/assets bruker riktig https-schema.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'auth.session' => Authenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
