<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Reconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Avstemming (reconciliation): bruker oppgir faktisk banksaldo, og vi gjør den
 * klarerte saldoen (sum av cleared-transaksjoner) lik den ved å bokføre en
 * ukategorisert «Avstemmingsjustering» for avviket. Justeringen er ukategorisert
 * og påvirker dermed Ready to Assign på budsjettkontoer (positivt avvik øker RTA,
 * negativt reduserer det), mens identiteten RTA + Σtilgjengelig = penger på konto
 * fortsatt holder. Alle klarerte rader stemples som avstemt.
 */
class ReconciliationService
{
    /**
     * Differanser under denne grensen regnes som null (avrundingsstøy), og det
     * lages ingen justeringstransaksjon.
     */
    private const EPSILON = 0.005;

    public function reconcile(Account $account, float $statementBalance, CarbonImmutable $date): Reconciliation
    {
        return DB::transaction(function () use ($account, $statementBalance, $date): Reconciliation {
            $clearedBalance = (float) $account->transactions()
                ->where('cleared', true)
                ->sum('amount');

            $difference = round($statementBalance - $clearedBalance, 2);
            $now = now();

            if (abs($difference) >= self::EPSILON) {
                $account->transactions()->create([
                    'date' => $date->toDateString(),
                    'amount' => $difference,
                    'payee' => 'Avstemmingsjustering',
                    'cleared' => true,
                    'reconciled_at' => $now,
                ]);
            } else {
                $difference = 0.0;
            }

            // Stemple alle klarerte, ennå ikke avstemte rader (inkl. en evt. ny justering).
            $account->transactions()
                ->where('cleared', true)
                ->whereNull('reconciled_at')
                ->update(['reconciled_at' => $now]);

            return $account->reconciliations()->create([
                'reconciled_at' => $now,
                'statement_balance' => round($statementBalance, 2),
                'cleared_balance' => round($clearedBalance, 2),
                'adjustment_amount' => $difference,
            ]);
        });
    }
}
