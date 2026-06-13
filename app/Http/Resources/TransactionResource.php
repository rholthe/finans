<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'category_id' => $this->category_id,
            'rule_id' => $this->rule_id,
            'locked' => $this->locked,
            'bank_description' => $this->bank_description,
            'date' => $this->date->toDateString(),
            'amount' => round((float) $this->amount, 2),
            'payee' => $this->payee,
            'memo' => $this->memo,
            'cleared' => $this->cleared,
            'is_starting_balance' => $this->is_starting_balance,
        ];
    }
}
