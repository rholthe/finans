<?php

namespace App\Http\Controllers;

use App\Enums\ScheduleFrequency;
use App\Http\Resources\ScheduledTransactionResource;
use App\Models\Account;
use App\Models\ScheduledTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScheduledTransactionController extends Controller
{
    /**
     * Alle planlagte transaksjoner, neste forfall først.
     */
    public function index(): AnonymousResourceCollection
    {
        $scheduled = ScheduledTransaction::query()
            ->orderBy('next_date')
            ->orderBy('id')
            ->get();

        return ScheduledTransactionResource::collection($scheduled);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $validated = $this->normalizeTransfer($validated, null);
        $validated['next_date'] = $validated['start_date'];

        $scheduled = ScheduledTransaction::create($validated);

        return ScheduledTransactionResource::make($scheduled)
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, ScheduledTransaction $scheduledTransaction): ScheduledTransactionResource
    {
        $validated = $this->validatePayload($request, partial: true);
        $validated = $this->normalizeTransfer($validated, $scheduledTransaction);

        // Hvis startdato flyttes før noe er postert (og neste forfall ikke settes
        // eksplisitt), flytt også neste forfall.
        if (
            isset($validated['start_date'])
            && ! isset($validated['next_date'])
            && $scheduledTransaction->last_posted_date === null
        ) {
            $validated['next_date'] = $validated['start_date'];
        }

        $scheduledTransaction->update($validated);

        return ScheduledTransactionResource::make($scheduledTransaction);
    }

    /**
     * En planlagt overføring (transfer_account_id satt) lagrer beløpet signert fra
     * «fra»-kontoens ståsted (negativt), og krever en kategori ved budsjett →
     * overvåket konto (samme regel som TransferService). For inngående/nøytrale
     * overføringer nulles kategorien.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeTransfer(array $validated, ?ScheduledTransaction $existing): array
    {
        $transferAccountId = $validated['transfer_account_id'] ?? $existing?->transfer_account_id;

        if ($transferAccountId === null) {
            // Vanlig planlagt: RTA og en konkret kategori utelukker hverandre.
            if (($validated['rta'] ?? false) === true) {
                $validated['category_id'] = null;
            } elseif (! empty($validated['category_id'])) {
                $validated['rta'] = false;
            }

            return $validated;
        }

        // Overføringer styrer RTA via retning, ikke et eget rta-flagg.
        $validated['rta'] = false;

        $fromId = $validated['account_id'] ?? $existing?->account_id;
        $from = Account::findOrFail($fromId);
        $to = Account::findOrFail($transferAccountId);

        if ($from->id === $to->id) {
            throw ValidationException::withMessages([
                'transfer_account_id' => 'Mottakerkontoen må være en annen enn fra-kontoen.',
            ]);
        }

        $budgetOutflow = $from->on_budget && ! $to->on_budget;

        if ($budgetOutflow && empty($validated['category_id'] ?? $existing?->category_id)) {
            throw ValidationException::withMessages([
                'category_id' => 'En planlagt overføring ut av budsjettet krever en kategori.',
            ]);
        }

        // Kategori gir bare mening for budsjett → overvåket.
        if (! $budgetOutflow) {
            $validated['category_id'] = null;
        }

        // Lagre beløpet signert fra fra-kontoens ståsted (penger ut = negativt).
        if (array_key_exists('amount', $validated)) {
            $validated['amount'] = -abs((float) $validated['amount']);
        }

        return $validated;
    }

    public function destroy(ScheduledTransaction $scheduledTransaction): JsonResponse
    {
        $scheduledTransaction->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'account_id' => [$required, Rule::exists('accounts', 'id')],
            'transfer_account_id' => ['nullable', Rule::exists('accounts', 'id')],
            'category_id' => ['nullable', Rule::exists('categories', 'id')],
            'rta' => ['sometimes', 'boolean'],
            'amount' => [$required, 'numeric'],
            'payee' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string'],
            'frequency' => [$required, Rule::enum(ScheduleFrequency::class)],
            'start_date' => [$required, 'date'],
            // Neste forfall kan flyttes ved redigering, men aldri bakover i tid.
            'next_date' => ['sometimes', 'date', 'after_or_equal:today'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
    }
}
