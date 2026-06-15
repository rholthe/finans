<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Oppretter en overføring som to sammenkoblede ben (ett på hver konto). Kategori
 * avhenger av budsjettgrensen (se også TransferController):
 * - budsjett ↔ budsjett / overvåket ↔ overvåket: ingen kategori (RTA-nøytral).
 * - budsjett → overvåket: budsjett-benet er kategorisert forbruk (krever kategori).
 * - overvåket → budsjett: budsjett-benet legges til RTA (rta=true).
 *
 * Validering av at kategori finnes ved budsjett→overvåket gjøres i kallende
 * kontroller; denne tjenesten materialiserer bare benene.
 */
class TransferService
{
    public function create(
        Account $from,
        Account $to,
        float $amount,
        string $date,
        ?string $memo = null,
        ?int $categoryId = null,
        ?int $scheduledTransactionId = null,
    ): Transaction {
        $amount = abs($amount);
        $budgetOutflow = $from->on_budget && ! $to->on_budget;
        $budgetInflow = ! $from->on_budget && $to->on_budget;

        return DB::transaction(function () use ($from, $to, $amount, $date, $memo, $categoryId, $scheduledTransactionId, $budgetOutflow, $budgetInflow): Transaction {
            $fromLeg = $from->transactions()->create([
                'scheduled_transaction_id' => $scheduledTransactionId,
                'date' => $date,
                'amount' => -$amount,
                'payee' => "Overføring til {$to->name}",
                'memo' => $memo,
                'category_id' => $budgetOutflow ? $categoryId : null,
                // Overføringer kan aldri redigeres/regelstyres → alltid låst.
                'locked' => true,
            ]);

            $toLeg = $to->transactions()->create([
                'scheduled_transaction_id' => $scheduledTransactionId,
                'date' => $date,
                'amount' => $amount,
                'payee' => "Overføring fra {$from->name}",
                'memo' => $memo,
                'transfer_id' => $fromLeg->id,
                'rta' => $budgetInflow,
                'locked' => true,
            ]);

            $fromLeg->update(['transfer_id' => $toLeg->id]);

            return $fromLeg;
        });
    }
}
