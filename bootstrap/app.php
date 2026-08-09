<?php

use App\Http\Middleware\Authenticated;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
