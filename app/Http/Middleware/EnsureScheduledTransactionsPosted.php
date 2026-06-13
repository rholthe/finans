<?php

namespace App\Http\Middleware;

use App\Services\ScheduledTransactionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sørger for at forfalte planlagte transaksjoner er postert før forespørselen
 * behandles, slik at saldoer og budsjett alltid er à jour – også når den
 * planlagte scheduler-jobben ikke har kjørt (f.eks. lokalt).
 */
class EnsureScheduledTransactionsPosted
{
    public function __construct(private readonly ScheduledTransactionService $service) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->service->postDue();

        return $next($request);
    }
}
