<?php

namespace App\Http\Controllers;

use App\Http\Resources\TransactionResource;
use App\Models\Account;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransferController extends Controller
{
    public function __construct(private readonly TransferService $transfers) {}

    /**
     * Opprett en overføring mellom to kontoer som to sammenkoblede transaksjoner
     * (ett ben på hver konto).
     *
     * Kategori avhenger av om benene krysser budsjettgrensen:
     * - budsjett ↔ budsjett / overvåket ↔ overvåket: ingen kategori (RTA-nøytral).
     * - budsjett → overvåket (penger ut av budsjettet): budsjett-benet er
     *   kategorisert forbruk og krever en kategori.
     * - overvåket → budsjett (penger inn): budsjett-benet er tilflyt og legges
     *   til RTA (rta=true).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_account_id' => ['required', Rule::exists('accounts', 'id')],
            'to_account_id' => ['required', 'different:from_account_id', Rule::exists('accounts', 'id')],
            'amount' => ['required', 'numeric', 'gt:0'],
            'date' => ['required', 'date'],
            'memo' => ['nullable', 'string'],
            'category_id' => ['nullable', Rule::exists('categories', 'id')],
        ]);

        $from = Account::findOrFail($validated['from_account_id']);
        $to = Account::findOrFail($validated['to_account_id']);
        $amount = (float) $validated['amount'];

        if ($from->on_budget && ! $to->on_budget && empty($validated['category_id'])) {
            throw ValidationException::withMessages([
                'category_id' => 'En overføring ut av budsjettet til en overvåket konto krever en kategori.',
            ]);
        }

        $fromLeg = $this->transfers->create(
            from: $from,
            to: $to,
            amount: $amount,
            date: $validated['date'],
            memo: $validated['memo'] ?? null,
            categoryId: $validated['category_id'] ?? null,
        );

        return TransactionResource::make($fromLeg->load('transfer.account'))
            ->response()
            ->setStatusCode(201);
    }
}
