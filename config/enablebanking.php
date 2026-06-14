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
];
