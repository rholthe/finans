<?php

namespace App\Http\Resources;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Account
 */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Saldo = sum av alle transaksjoner. Bruk forhåndsberegnet sum
        // (withSum/loadSum) når den finnes, ellers beregn direkte.
        $balance = $this->transactions_sum_amount ?? $this->transactions()->sum('amount');

        // Klarert saldo = sum av klarerte transaksjoner (grunnlaget for avstemming).
        $clearedBalance = $this->cleared_transactions_sum_amount
            ?? $this->transactions()->where('cleared', true)->sum('amount');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'on_budget' => $this->on_budget,
            'currency' => $this->currency,
            'closed' => $this->closed,
            'note' => $this->note,
            'balance' => round((float) $balance, 2),
            'cleared_balance' => round((float) $clearedBalance, 2),
            'last_reconciled_at' => $this->reconciliations()->max('reconciled_at'),
            // Antall transaksjoner som mangler aktiv kategorisering (badge/varsling).
            'uncategorized_count' => $this->needs_categorization_count
                ?? $this->transactions()->needsCategorization()->count(),
            // Koblet til banksynk? (Overføringsregler kan kun peke på ikke-synkede.)
            'bank_synced' => (bool) ($this->bank_accounts_exists ?? $this->bankAccounts()->exists()),
            // Bankens egen saldo fra siste synk (kun når bankAccounts er lastet,
            // dvs. på kontodetalj). Aggregert over koblede kontoer med saldo.
            'bank_balance' => $this->whenLoaded('bankAccounts', fn () => $this->bankBalancePayload()),
        ];
    }

    /**
     * Bankens saldo aggregert over de koblede bankkontoene som har en synket saldo.
     * Null hvis ingen er synket ennå.
     *
     * @return array{booked: float, available: float, synced_at: string|null}|null
     */
    private function bankBalancePayload(): ?array
    {
        $synced = $this->bankAccounts->whereNotNull('balance_synced_at');

        if ($synced->isEmpty()) {
            return null;
        }

        return [
            'booked' => round((float) $synced->sum('balance_booked'), 2),
            'available' => $this->bankAvailableBalance(),
            'synced_at' => $synced->max('balance_synced_at')?->toISOString(),
        ];
    }
}
