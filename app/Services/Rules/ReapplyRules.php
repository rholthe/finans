<?php

namespace App\Services\Rules;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
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
     * Kjør reglene på nytt mot alle bank-importerte, ulåste transaksjoner.
     * (CLI-nødutgang – UI bruker den avgrensede varianten.)
     *
     * @return int Antall transaksjoner som faktisk ble endret
     */
    public function run(): int
    {
        $this->rules->refresh();
        $changed = 0;

        $this->baseQuery()
            ->chunkById(200, function ($transactions) use (&$changed): void {
                foreach ($transactions as $transaction) {
                    if ($this->reapply($transaction)) {
                        $changed++;
                    }
                }
            });

        return $changed;
    }

    /**
     * Kjør reglene på et avgrenset sett transaksjoner (det brukeren ser etter
     * filtrering/paginering). Hopper alltid over låste og allerede matchede.
     *
     * @param  array<int, int>  $ids
     * @return int Antall endret
     */
    public function applyToIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $this->rules->refresh();
        $changed = 0;

        $this->baseQuery()
            ->whereIn('id', $ids)
            ->whereNull('rule_id')
            ->chunkById(200, function ($transactions) use (&$changed): void {
                foreach ($transactions as $transaction) {
                    if ($this->reapply($transaction)) {
                        $changed++;
                    }
                }
            });

        return $changed;
    }

    /**
     * Bank-importerte, ulåste transaksjoner (matchegrunnlag + beskyttelse).
     *
     * @return Builder<Transaction>
     */
    private function baseQuery(): Builder
    {
        return Transaction::query()
            ->whereNotNull('bank_description')
            ->where('locked', false);
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
