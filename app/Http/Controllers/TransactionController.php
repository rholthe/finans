<?php

namespace App\Http\Controllers;

use App\Http\Resources\TransactionResource;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    /**
     * Transaksjoner for en konto, nyeste først.
     */
    public function index(Account $account): AnonymousResourceCollection
    {
        $transactions = $account->transactions()
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(100);

        return TransactionResource::collection($transactions);
    }

    public function store(Request $request, Account $account): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $transaction = $account->transactions()->create($validated);

        return TransactionResource::make($transaction)
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Transaction $transaction): TransactionResource
    {
        $validated = $this->validatePayload($request, partial: true);

        $transaction->update($validated);

        return TransactionResource::make($transaction);
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        $transaction->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'category_id' => ['nullable', Rule::exists('categories', 'id')],
            'date' => [$required, 'date'],
            'amount' => [$required, 'numeric'],
            'payee' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string'],
            'cleared' => ['sometimes', 'boolean'],
        ]);
    }
}
