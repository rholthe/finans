<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CategoryGroupController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\RuleController;
use App\Http\Controllers\ScheduledTransactionController;
use App\Http\Controllers\TransactionController;
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

        Route::apiResource('scheduled-transactions', ScheduledTransactionController::class)
            ->only(['index', 'store', 'update', 'destroy']);
        Route::get('accounts/{account}/transactions', [TransactionController::class, 'index']);
        Route::post('accounts/{account}/transactions', [TransactionController::class, 'store']);
        Route::post('transactions/apply-rules', [TransactionController::class, 'applyRules']);
        Route::apiResource('transactions', TransactionController::class)->only(['update', 'destroy']);

        Route::apiResource('category-groups', CategoryGroupController::class)->except(['show']);
        Route::apiResource('categories', CategoryController::class)->only(['store', 'update', 'destroy']);

        Route::put('categories/{category}/goal', [GoalController::class, 'upsert']);
        Route::delete('categories/{category}/goal', [GoalController::class, 'destroy']);

        Route::get('budget', [BudgetController::class, 'show']);
        Route::put('budget/{month}/categories/{category}', [BudgetController::class, 'assign'])
            ->where('month', '\d{4}-\d{2}');
        Route::post('budget/{month}/categories/{category}/fund', [BudgetController::class, 'fundCategory'])
            ->where('month', '\d{4}-\d{2}');
        Route::post('budget/{month}/auto-assign', [BudgetController::class, 'autoAssign'])
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
        Route::get('bank/callback', [BankController::class, 'callback']);
        Route::put('bank/accounts/{bankAccount}', [BankController::class, 'linkAccount']);
        Route::delete('bank/connections/{bankConnection}', [BankController::class, 'deleteConnection']);
        Route::post('bank/sync', [BankController::class, 'sync']);
    });
});

/*
|--------------------------------------------------------------------------
| SPA – alt annet serveres av React (client-side routing)
|--------------------------------------------------------------------------
*/
Route::get('/{any?}', fn () => view('app'))
    ->where('any', '^(?!api).*$');
