<?php

namespace App\Http\Controllers;

use App\Http\Resources\TransactionResource;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TransferController extends Controller
{
    /**
     * Opprett en overføring mellom to kontoer som to sammenkoblede, ukategoriserte
     * transaksjoner (ett ben på hver konto). Overføringer påvirker ikke Ready to
     * Assign – benene nuller hverandre ut – og en overføring inn på et kredittkort
     * tømmer kortets betalingskategori (se BudgetService).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_account_id' => ['required', Rule::exists('accounts', 'id')],
            'to_account_id' => ['required', 'different:from_account_id', Rule::exists('accounts', 'id')],
            'amount' => ['required', 'numeric', 'gt:0'],
            'date' => ['required', 'date'],
            'memo' => ['nullable', 'string'],
        ]);

        $from = Account::findOrFail($validated['from_account_id']);
        $to = Account::findOrFail($validated['to_account_id']);
        $amount = (float) $validated['amount'];

        $fromLeg = DB::transaction(function () use ($from, $to, $amount, $validated): Transaction {
            $fromLeg = $from->transactions()->create([
                'date' => $validated['date'],
                'amount' => -$amount,
                'payee' => "Overføring til {$to->name}",
                'memo' => $validated['memo'] ?? null,
            ]);

            $toLeg = $to->transactions()->create([
                'date' => $validated['date'],
                'amount' => $amount,
                'payee' => "Overføring fra {$from->name}",
                'memo' => $validated['memo'] ?? null,
                'transfer_id' => $fromLeg->id,
            ]);

            $fromLeg->update(['transfer_id' => $toLeg->id]);

            return $fromLeg;
        });

        return TransactionResource::make($fromLeg->load('transfer.account'))
            ->response()
            ->setStatusCode(201);
    }
}
