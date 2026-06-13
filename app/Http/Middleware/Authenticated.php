<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Authenticated
{
    /**
     * Beskytt API-ruter: krever en innlogget økt.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('authenticated', false)) {
            return response()->json(['message' => 'Ikke innlogget.'], 401);
        }

        return $next($request);
    }
}
