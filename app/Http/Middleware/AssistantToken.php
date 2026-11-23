<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nøkkelen til den personlige assistenten (Munin, assistent.holthe.org).
 *
 * Bare lesing: kun GET slipper gjennom, og bare under /api/assistent/* (rutene i
 * routes/assistent.php). Nøkkelen er ASSISTENT_API_TOKEN; uten den er endepunktene
 * stengt. Nøkkelen gjelder bare over det interne Docker-nettet: en forespørsel som har
 * gått via Caddy (X-Forwarded-For/-Proto) avvises, selv med riktig nøkkel – samme
 * sperre som i trening-dashboardet.
 */
class AssistantToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->headers->has('X-Forwarded-For') || $request->headers->has('X-Forwarded-Proto')) {
            return response()->json(['message' => 'Assistent-API-et er bare tilgjengelig internt.'], 403);
        }

        $expected = (string) config('services.assistent.token');
        $given = (string) $request->bearerToken();

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Ugyldig eller manglende nøkkel.'], 401);
        }

        if (! $request->isMethod('GET')) {
            return response()->json(['message' => 'Assistenten har bare lesetilgang.'], 405);
        }

        return $next($request);
    }
}
