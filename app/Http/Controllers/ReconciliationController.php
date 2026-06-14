<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Services\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function __construct(private readonly ReconciliationService $reconciliation) {}

    /**
     * Avstem en konto mot oppgitt faktisk banksaldo. Lager en justering ved
     * avvik og stempler klarerte rader som avstemt.
     */
    public function store(Request $request, Account $account): JsonResponse
    {
        $validated = $request->validate([
            'statement_balance' => ['required', 'numeric'],
            'date' => ['sometimes', 'date'],
        ]);

        $date = isset($validated['date'])
            ? CarbonImmutable::parse($validated['date'])
            : CarbonImmutable::now();

        $reconciliation = $this->reconciliation->reconcile(
            $account,
            (float) $validated['statement_balance'],
            $date,
        );

        return response()->json([
            'account' => AccountResource::make($account->loadSum('transactions', 'amount')),
            'cleared_balance' => round((float) $reconciliation->cleared_balance, 2),
            'adjustment_amount' => round((float) $reconciliation->adjustment_amount, 2),
        ]);
    }
}
