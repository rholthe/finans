<?php

use App\Http\Controllers\AssistantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Assistent-API (/api/assistent/*) – bare lesing
|--------------------------------------------------------------------------
| For den personlige assistenten (Munin). Registreres i bootstrap/app.php uten
| web-gruppen (ingen session/CSRF), bak AssistantToken (nøkkel + bare GET) og
| EnsureScheduledTransactionsPosted (samme postering som når appen åpnes).
| Caddy avviser stien utenfra; kallene går over det interne Docker-nettet.
*/
Route::get('overview', [AssistantController::class, 'overview']);
Route::get('budget', [AssistantController::class, 'budget']);
Route::get('transactions', [AssistantController::class, 'transactions']);
Route::get('reports/{type}', [AssistantController::class, 'report'])
    ->whereIn('type', ['spending', 'income-expense', 'category-trend', 'net-worth']);
Route::get('upcoming', [AssistantController::class, 'upcoming']);
Route::get('loans', [AssistantController::class, 'loans']);
