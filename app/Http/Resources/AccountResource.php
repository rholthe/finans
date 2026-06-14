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
        ];
    }
}
