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
            'pending' => $this->pending,
            'rta' => $this->rta,
            'is_split' => $this->is_split,
            'splits' => $this->whenLoaded('splits', fn () => $this->splits->map(fn ($s): array => [
                'id' => $s->id,
                'category_id' => $s->category_id,
                'amount' => round((float) $s->amount, 2),
                'memo' => $s->memo,
            ])->all()),
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
            'is_starting_balance' => $this->is_starting_balance,
            'transfer_id' => $this->transfer_id,
            // Den andre kontoen i overføringen (for visning), når relasjonen er lastet.
            'transfer_account' => $this->when(
                $this->transfer_id !== null && $this->relationLoaded('transfer'),
                fn () => $this->transfer?->account?->name,
            ),
            // Konto-/kategorinavn for kontouavhengig visning (kun når relasjonen er
            // lastet, dvs. i søk – per-konto-listen laster dem ikke).
            'account' => $this->whenLoaded('account', fn () => $this->account?->name),
            'category' => $this->whenLoaded('category', fn () => $this->category?->name),
        ];
    }
}
