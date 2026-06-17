<?php

use App\Services\Bank\Mapping\SandboxBankMapping;

return [
    /*
    |--------------------------------------------------------------------------
    | GoCardless Bank Account Data
    |--------------------------------------------------------------------------
    |
    | Konfigurasjon for bankintegrasjonen. Appen er for én bruker, så vi bruker
    | ett nøkkelpar fra .env (ikke en nøkkeltabell). Ved testing brukes sandbox-
    | banken siden ekte banker har en lav rate-limit (4 spørringer/endepunkt/døgn).
    |
    */

    'base_uri' => env('GOCARDLESS_BASE_URI', 'https://bankaccountdata.gocardless.com/api/v2'),
    'redirect_uri' => env('GOCARDLESS_REDIRECT_URI'),
    'secret_id' => env('GOCARDLESS_SECRET_ID'),
    'secret_key' => env('GOCARDLESS_SECRET_KEY'),
    'sandbox_bank' => env('GOCARDLESS_SANDBOX_BANK', 'SANDBOXFINANCE_SFIN0000'),

    // Antall dager bakover synken henter transaksjoner for.
    'sync_days' => (int) env('BANK_SYNC_DAYS', 90),

    // E-postadresse rapporten sendes til etter hver synk (vellykket og mislykket).
    // Samme adresse mottar varsel om at en bankgodkjenning snart utløper.
    'report_email' => env('BANK_SYNC_REPORT_EMAIL'),

    // Antall dager før utløp vi varsler om at en bankgodkjenning må fornyes.
    'expiry_warning_days' => (int) env('BANK_CONSENT_EXPIRY_WARNING_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Bank-spesifikk feltmapping
    |--------------------------------------------------------------------------
    |
    | Mapper en institusjons-id til en mapping-strategi for payee/memo-kilden.
    | Ukjente institusjoner faller tilbake til DefaultMapping.
    |
    */
    'mappings' => [
        env('GOCARDLESS_SANDBOX_BANK', 'SANDBOXFINANCE_SFIN0000') => SandboxBankMapping::class,
    ],
];
