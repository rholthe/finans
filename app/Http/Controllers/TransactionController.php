<?php

namespace App\Http\Controllers;

use App\Http\Resources\TransactionResource;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\Rules\ReapplyRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    /**
     * Transaksjoner for en konto, nyeste først. Støtter datofilter (from/to)
     * og valgbar sidestørrelse (per_page).
     */
    public function index(Request $request, Account $account): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $transactions = $account->transactions()
            ->with('transfer.account')
            ->when($validated['from'] ?? null, fn ($q, $from) => $q->whereDate('date', '>=', $from))
            ->when($validated['to'] ?? null, fn ($q, $to) => $q->whereDate('date', '<=', $to))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 100)
            ->withQueryString();

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

    public function update(Request $request, Transaction $transaction): TransactionResource|JsonResponse
    {
        // Overføringer er to sammenkoblede ben; beløp/dato/kategori kan ikke endres
        // (det ville desynke paret). Men hvert ben må kunne klareres uavhengig –
        // de posteres på hver sin konto til ulik tid – så `cleared` tillates.
        if ($transaction->transfer_id !== null) {
            if (collect($request->keys())->diff(['cleared'])->isNotEmpty()) {
                return response()->json(
                    ['message' => 'Overføringer kan ikke redigeres – slett og opprett på nytt.'],
                    422,
                );
            }

            $validated = $request->validate(['cleared' => ['required', 'boolean']]);
            $transaction->update($validated);

            return TransactionResource::make($transaction);
        }

        $validated = $this->validatePayload($request, partial: true);

        // En manuell endring av regelstyrte felter låser raden, slik at
        // regelmotoren aldri overskriver den senere.
        if ($request->hasAny(['payee', 'memo', 'category_id'])) {
            $validated['locked'] = true;
        }

        $transaction->update($validated);

        return TransactionResource::make($transaction);
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        // En overføring slettes som et hele: fjern begge ben.
        DB::transaction(function () use ($transaction): void {
            $transaction->transfer?->delete();
            $transaction->delete();
        });

        return response()->json(status: 204);
    }

    /**
     * Kjør reglene på et avgrenset sett transaksjoner (det brukeren ser etter
     * filtrering/paginering). Låste og – som standard – allerede matchede hoppes over.
     */
    public function applyRules(Request $request, ReapplyRules $service): JsonResponse
    {
        $validated = $request->validate([
            'transaction_ids' => ['required', 'array'],
            'transaction_ids.*' => ['integer'],
            'include_matched' => ['sometimes', 'boolean'],
        ]);

        $updated = $service->applyToIds(
            $validated['transaction_ids'],
            $validated['include_matched'] ?? false,
        );

        return response()->json(['updated' => $updated]);
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
            'locked' => ['sometimes', 'boolean'],
        ]);
    }
}
