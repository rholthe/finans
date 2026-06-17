<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CategoryGroupController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RuleController;
use App\Http\Controllers\ScheduledTransactionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransferController;
use App\Http\Middleware\EnsureScheduledTransactionsPosted;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API-ruter (samme origin, session-basert auth)
|--------------------------------------------------------------------------
| Ligger under web-middleware slik at vi får session + CSRF-beskyttelse.
| Beskyttede ruter krever den innloggede økten ('auth.session').
*/
Route::prefix('api')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::middleware(['auth.session', EnsureScheduledTransactionsPosted::class])->group(function () {
        Route::get('/ping', fn () => response()->json(['pong' => true]));

        Route::apiResource('accounts', AccountController::class);
        Route::post('accounts/{account}/reconcile', [ReconciliationController::class, 'store']);

        Route::apiResource('scheduled-transactions', ScheduledTransactionController::class)
            ->only(['index', 'store', 'update', 'destroy']);
        Route::get('accounts/{account}/transactions', [TransactionController::class, 'index']);
        Route::post('accounts/{account}/transactions', [TransactionController::class, 'store']);
        Route::post('transactions/apply-rules', [TransactionController::class, 'applyRules']);
        Route::apiResource('transactions', TransactionController::class)->only(['update', 'destroy']);

        // Overføring mellom to kontoer (parvise transaksjoner; sletting via transactions.destroy)
        Route::post('transfers', [TransferController::class, 'store']);

        Route::apiResource('category-groups', CategoryGroupController::class)->except(['show']);
        Route::apiResource('categories', CategoryController::class)->only(['store', 'update', 'destroy']);

        Route::put('categories/{category}/goal', [GoalController::class, 'upsert']);
        Route::delete('categories/{category}/goal', [GoalController::class, 'destroy']);

        Route::get('budget', [BudgetController::class, 'show']);
        Route::put('budget/{month}/categories/{category}', [BudgetController::class, 'assign'])
            ->where('month', '\d{4}-\d{2}');
        Route::get('budget/{month}/categories/{category}/transactions', [BudgetController::class, 'categoryTransactions'])
            ->where('month', '\d{4}-\d{2}');
        Route::post('budget/{month}/categories/{category}/fund', [BudgetController::class, 'fundCategory'])
            ->where('month', '\d{4}-\d{2}');
        Route::post('budget/{month}/categories/{category}/move', [BudgetController::class, 'move'])
            ->where('month', '\d{4}-\d{2}');
        Route::post('budget/{month}/auto-assign', [BudgetController::class, 'autoAssign'])
            ->where('month', '\d{4}-\d{2}');
        Route::post('budget/{month}/sweep', [BudgetController::class, 'sweep'])
            ->where('month', '\d{4}-\d{2}');
        Route::post('budget/{month}/reset-assignments', [BudgetController::class, 'resetAssignments'])
            ->where('month', '\d{4}-\d{2}');

        // Regelmotor (payee/memo/kategori) – leverandøruavhengig.
        // Global re-kjøring finnes kun som CLI (rules:reapply); UI bruker den
        // avgrensede transactions/apply-rules på et filtrert/synlig sett.
        Route::put('rules/reorder', [RuleController::class, 'reorder']);
        Route::apiResource('rules', RuleController::class)->only(['index', 'store', 'update', 'destroy']);

        // Bankintegrasjon (GoCardless bak BankDataProvider)
        Route::get('bank/institutions', [BankController::class, 'institutions']);
        Route::get('bank/connections', [BankController::class, 'connections']);
        Route::post('bank/connect', [BankController::class, 'connect']);
        Route::post('bank/connections/{bankConnection}/renew', [BankController::class, 'renew']);
        Route::get('bank/callback', [BankController::class, 'callback']);
        Route::put('bank/accounts/{bankAccount}', [BankController::class, 'linkAccount']);
        Route::delete('bank/connections/{bankConnection}', [BankController::class, 'deleteConnection']);
        Route::post('bank/sync', [BankController::class, 'sync']);
        Route::get('bank/sync-status/{syncEvent}', [BankController::class, 'syncStatus']);

        // Rapporter (aggregeringer fra transactions)
        Route::get('reports/spending', [ReportController::class, 'spending']);
        Route::get('reports/income-expense', [ReportController::class, 'incomeExpense']);
        Route::get('reports/category-trend', [ReportController::class, 'categoryTrend']);
        Route::get('reports/net-worth', [ReportController::class, 'netWorth']);

        Route::get('settings', [SettingsController::class, 'show']);
        Route::put('settings', [SettingsController::class, 'update']);
    });
});

/*
|--------------------------------------------------------------------------
| Offentlige juridiske sider (utenfor passord-login og SPA)
|--------------------------------------------------------------------------
| Personvern + vilkår må være offentlig tilgjengelige (Enable Banking peker
| hit i sin prod-app-godkjenning). Frittstående Blade, ingen auth.
*/
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms', 'legal.terms')->name('legal.terms');

/*
|--------------------------------------------------------------------------
| SPA – alt annet serveres av React (client-side routing)
|--------------------------------------------------------------------------
*/
Route::get('/{any?}', fn () => view('app'))
    ->where('any', '^(?!api|privacy|terms).*$');
