<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Poster forfalte planlagte transaksjoner én gang i døgnet.
Schedule::command('transactions:post-due')->dailyAt('00:05');

// Hent nye banktransaksjoner én gang i døgnet (full kø-/robusthet kommer i Fase 6).
Schedule::command('bank:sync')->dailyAt('05:00');
