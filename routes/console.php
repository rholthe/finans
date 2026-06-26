<?php

use App\Jobs\SyncBankTransactionsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Poster forfalte planlagte transaksjoner én gang i døgnet.
Schedule::command('transactions:post-due')->dailyAt('00:05')->withoutOverlapping();

// Nattlig banksynk: legg den køede jobben i kø (auto-trigger → auto_sync_days).
Schedule::job(new SyncBankTransactionsJob(trigger: 'auto'))->dailyAt('05:00');

// Varsle om bankgodkjenninger som snart utløper (etter synk har oppdatert valid_until).
Schedule::command('bank:check-expiry')->dailyAt('06:00')->withoutOverlapping();

// Poster månedlig rente på lånekontoer med effektiv rente satt (natt til den 1.).
Schedule::command('loans:post-interest')->monthlyOn(1, '00:10')->withoutOverlapping();
