<?php

namespace App\Http\Controllers;

use App\Enums\ScheduleFrequency;
use App\Http\Resources\ScheduledTransactionResource;
use App\Models\ScheduledTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

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
        $validated['next_date'] = $validated['start_date'];

        $scheduled = ScheduledTransaction::create($validated);

        return ScheduledTransactionResource::make($scheduled)
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, ScheduledTransaction $scheduledTransaction): ScheduledTransactionResource
    {
        $validated = $this->validatePayload($request, partial: true);

        // Hvis startdato flyttes før noe er postert, flytt også neste forfall.
        if (isset($validated['start_date']) && $scheduledTransaction->last_posted_date === null) {
            $validated['next_date'] = $validated['start_date'];
        }

        $scheduledTransaction->update($validated);

        return ScheduledTransactionResource::make($scheduledTransaction);
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
            'category_id' => ['nullable', Rule::exists('categories', 'id')],
            'amount' => [$required, 'numeric'],
            'payee' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string'],
            'frequency' => [$required, Rule::enum(ScheduleFrequency::class)],
            'start_date' => [$required, 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
    }
}
