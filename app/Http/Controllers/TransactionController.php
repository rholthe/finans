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
use Illuminate\Validation\ValidationException;

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
            'uncategorized' => ['nullable', 'boolean'],
        ]);

        $transactions = $account->transactions()
            ->with(['transfer.account', 'splits'])
            ->when($validated['from'] ?? null, fn ($q, $from) => $q->whereDate('date', '>=', $from))
            ->when($validated['to'] ?? null, fn ($q, $to) => $q->whereDate('date', '<=', $to))
            ->when($validated['uncategorized'] ?? false, fn ($q) => $q->needsCategorization())
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
        // de posteres på hver sin konto til ulik tid – så `cleared` tillates. I
        // tillegg kan det kategoriserte benet (budsjett→overvåket) splittes, siden
        // det må kategoriseres; øvrige overføringsben kan ikke endres.
        if ($transaction->transfer_id !== null) {
            $splittable = $transaction->category_id !== null || $transaction->is_split;
            $allowed = $splittable ? ['cleared', 'splits'] : ['cleared'];
            if (collect($request->keys())->diff($allowed)->isNotEmpty()) {
                return response()->json(
                    ['message' => 'Overføringer kan ikke redigeres – slett og opprett på nytt.'],
                    422,
                );
            }
        }

        $validated = $this->validatePayload($request, partial: true);
        $splitsProvided = array_key_exists('splits', $validated);
        $splits = $validated['splits'] ?? [];
        unset($validated['splits']);

        return DB::transaction(function () use ($request, $transaction, $validated, $splitsProvided, $splits) {
            // RTA og en konkret kategori utelukker hverandre: «Klar til å fordele»
            // betyr ukategorisert + rta=true; en konkret kategori nullstiller rta.
            if (! $splitsProvided) {
                if (($validated['rta'] ?? false) === true) {
                    $validated['category_id'] = null;
                } elseif (! empty($validated['category_id'])) {
                    $validated['rta'] = false;
                }

                // En konkret kategori/RTA på en tidligere splittet rad fjerner splittene.
                if ($transaction->is_split && ($request->has('category_id') || ($validated['rta'] ?? false))) {
                    $transaction->splits()->delete();
                    $validated['is_split'] = false;
                }
            }

            // En manuell endring av regelstyrte felter (inkl. RTA/splitt) låser raden,
            // slik at regelmotoren aldri overskriver den senere.
            if ($request->hasAny(['payee', 'memo', 'category_id', 'rta', 'splits'])) {
                $validated['locked'] = true;
            }

            $transaction->update($validated);

            if ($splitsProvided) {
                $this->syncSplits($transaction->refresh(), $splits);
            }

            return TransactionResource::make($transaction->load('splits'));
        });
    }

    /**
     * Skriv splittlinjene for en transaksjon. Tom liste fjerner splitten. Ellers
     * må linjene ha minst to oppføringer, samme fortegn som beløpet, og summere
     * nøyaktig til transaksjonsbeløpet – pengeraden selv er uendret.
     *
     * @param  list<array{category_id: int, amount: float|int|string, memo?: string|null}>  $splits
     *
     * @throws ValidationException
     */
    private function syncSplits(Transaction $transaction, array $splits): void
    {
        if ($splits === []) {
            $transaction->splits()->delete();
            $transaction->update(['is_split' => false]);

            return;
        }

        if (count($splits) < 2) {
            throw ValidationException::withMessages(['splits' => 'En splitt må ha minst to linjer.']);
        }

        $total = round((float) $transaction->amount, 2);
        $sign = $total <=> 0;
        $sum = 0.0;

        foreach ($splits as $line) {
            $amount = round((float) $line['amount'], 2);
            if ($amount === 0.0 || ($amount <=> 0) !== $sign) {
                throw ValidationException::withMessages([
                    'splits' => 'Hver splittlinje må ha samme fortegn som transaksjonsbeløpet.',
                ]);
            }
            $sum += $amount;
        }

        if (abs(round($sum - $total, 2)) > 0.001) {
            throw ValidationException::withMessages([
                'splits' => 'Summen av splittene må være lik transaksjonsbeløpet.',
            ]);
        }

        $transaction->splits()->delete();
        foreach ($splits as $line) {
            $transaction->splits()->create([
                'category_id' => $line['category_id'],
                'amount' => round((float) $line['amount'], 2),
                'memo' => $line['memo'] ?? null,
            ]);
        }

        $transaction->update(['category_id' => null, 'is_split' => true, 'rta' => false]);
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
     * filtrering/paginering). Låste og allerede matchede hoppes alltid over.
     */
    public function applyRules(Request $request, ReapplyRules $service): JsonResponse
    {
        $validated = $request->validate([
            'transaction_ids' => ['required', 'array'],
            'transaction_ids.*' => ['integer'],
        ]);

        $updated = $service->applyToIds($validated['transaction_ids']);

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
            'rta' => ['sometimes', 'boolean'],
            // Splitt på flere kategorier: tom liste fjerner splitten (sum/fortegn
            // valideres i syncSplits siden de avhenger av transaksjonsbeløpet).
            'splits' => ['sometimes', 'array'],
            'splits.*.category_id' => ['required', Rule::exists('categories', 'id')],
            'splits.*.amount' => ['required', 'numeric'],
            'splits.*.memo' => ['nullable', 'string'],
        ]);
    }
}
