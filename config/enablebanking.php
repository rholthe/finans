<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Banking
    |--------------------------------------------------------------------------
    |
    | Bankleverandør nr. 2 (gratis tier for personlig, ikke-kommersiell bruk).
    | Autentisering skjer med et selvsignert RS256-JWT: registrer en applikasjon
    | hos Enable Banking, last ned den private nøkkelen og oppgi applikasjons-id-en
    | (brukes som JWT-«kid»).
    |
    | ENABLEBANKING_PRIVATE_KEY kan være enten PEM-innholdet (med \n) eller en
    | filsti til .pem-nøkkelen.
    |
    */

    'base_uri' => env('ENABLEBANKING_BASE_URI', 'https://api.enablebanking.com'),
    'application_id' => env('ENABLEBANKING_APPLICATION_ID'),
    'private_key' => env('ENABLEBANKING_PRIVATE_KEY'),
    'redirect_uri' => env('ENABLEBANKING_REDIRECT_URI', env('GOCARDLESS_REDIRECT_URI')),
    'country' => env('ENABLEBANKING_COUNTRY', 'NO'),

    /*
    | PSU-kontekst (Berlin Group/PSD2). Flere ASPSP-er oppgir `psu-ip-address` i
    | `required_psu_headers` og avviser kall uten den (f.eks. Bulder → ASPSP_ERROR
    | ved uovervåket synk). Ved tilstedeværende bruker sendes sluttbrukerens reelle
    | IP; ved uovervåket synk (cron) finnes ingen PSU, så denne fallbacken (typisk
    | serverens utgående offentlige IP) brukes. Står den tom sendes ingen
    | psu-ip-address ved uovervåket synk (uendret oppførsel).
    */
    'psu_ip' => env('ENABLEBANKING_PSU_IP'),
    'psu_user_agent' => env('ENABLEBANKING_PSU_USER_AGENT', 'Finans/1.0'),
];
