<?php

namespace App\Http\Resources;

use App\Models\ScheduledTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduledTransaction
 */
class ScheduledTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'transfer_account_id' => $this->transfer_account_id,
            'category_id' => $this->category_id,
            'rta' => $this->rta,
            'amount' => round((float) $this->amount, 2),
            'payee' => $this->payee,
            'memo' => $this->memo,
            'frequency' => $this->frequency->value,
            'start_date' => $this->start_date->toDateString(),
            'next_date' => $this->next_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'last_posted_date' => $this->last_posted_date?->toDateString(),
        ];
    }
}
