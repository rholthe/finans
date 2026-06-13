<?php

namespace App\Services\Rules;

use App\Models\Transaction;
use Illuminate\Support\Str;

/**
 * Kjører regelmotoren på nytt mot alle bank-importerte transaksjoner (de som
 * har bevart `bank_description`) og oppdaterer payee/memo/kategori. Overstyrer
 * eksisterende verdier – også manuelle endringer (beskyttelse kan komme senere).
 */
class ReapplyRules
{
    public function __construct(private readonly RuleEngine $rules) {}

    /**
     * @return int Antall transaksjoner som faktisk ble endret
     */
    public function run(): int
    {
        $this->rules->refresh();
        $changed = 0;

        Transaction::query()
            ->whereNotNull('bank_description')
            ->chunkById(200, function ($transactions) use (&$changed): void {
                foreach ($transactions as $transaction) {
                    if ($this->reapply($transaction)) {
                        $changed++;
                    }
                }
            });

        return $changed;
    }

    private function reapply(Transaction $transaction): bool
    {
        $result = $this->rules->apply((string) $transaction->bank_description, (float) $transaction->amount);

        $new = [
            'payee' => $result->payee ?? Str::limit((string) $transaction->bank_description, 255, ''),
            'memo' => $result->memo ?? $transaction->bank_description,
            'category_id' => $result->categoryId,
            'rule_id' => $result->ruleId,
        ];

        $transaction->fill($new);

        if (! $transaction->isDirty()) {
            return false;
        }

        $transaction->save();

        return true;
    }
}
