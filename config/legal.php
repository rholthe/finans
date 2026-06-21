<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Legal / operator details
    |--------------------------------------------------------------------------
    |
    | Operatørinfo som vises på de offentlige personvern- og vilkårssidene
    | (/privacy og /terms – kreves av Enable Banking for app-godkjenning).
    | Sett disse i .env for din egen instans. `domain` faller tilbake til
    | verten i APP_URL hvis LEGAL_DOMAIN ikke er satt; `operator_email` kan
    | stå tom (da skjules kontakt-e-posten på sidene).
    |
    */

    'operator_name' => env('LEGAL_OPERATOR_NAME', env('APP_NAME', 'Finans')),

    'operator_email' => env('LEGAL_OPERATOR_EMAIL'),

    'domain' => env('LEGAL_DOMAIN') ?: parse_url((string) env('APP_URL'), PHP_URL_HOST),
];
