<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    /**
     * Alle kontoer med beregnet saldo.
     */
    public function index(): AnonymousResourceCollection
    {
        $accounts = Account::query()
            ->withSum('transactions', 'amount')
            ->orderBy('closed')
            ->orderBy('name')
            ->get();

        return AccountResource::collection($accounts);
    }

    /**
     * Opprett konto. Et valgfritt startbeløp lagres som en egen
     * «startsaldo»-transaksjon slik at saldo alltid = sum(transaksjoner).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'on_budget' => ['boolean'],
            'note' => ['nullable', 'string'],
            'starting_balance' => ['nullable', 'numeric'],
        ]);

        $account = DB::transaction(function () use ($validated, $request) {
            $account = Account::create([
                'name' => $validated['name'],
                'type' => $validated['type'],
                'on_budget' => $validated['on_budget'] ?? true,
                'currency' => 'NOK',
                'closed' => false,
                'note' => $validated['note'] ?? null,
            ]);

            $starting = (float) ($validated['starting_balance'] ?? 0);
            if ($starting !== 0.0) {
                $account->transactions()->create([
                    'date' => $request->date('starting_balance_date') ?? now(),
                    'amount' => $starting,
                    'payee' => 'Startsaldo',
                    'cleared' => true,
                    'is_starting_balance' => true,
                ]);
            }

            return $account;
        });

        return AccountResource::make($account->loadSum('transactions', 'amount'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Account $account): AccountResource
    {
        return AccountResource::make($account->loadSum('transactions', 'amount'));
    }

    public function update(Request $request, Account $account): AccountResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::enum(AccountType::class)],
            'on_budget' => ['sometimes', 'boolean'],
            'closed' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string'],
        ]);

        $account->update($validated);

        return AccountResource::make($account->loadSum('transactions', 'amount'));
    }

    public function destroy(Account $account): JsonResponse
    {
        $account->delete();

        return response()->json(status: 204);
    }
}
