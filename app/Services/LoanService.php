<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Lånelogikk: månedlig renteberegning (autopostert) og nedbetalingsprojeksjon.
 *
 * Saldo på en lånekonto er negativ (gjeld). Renter øker gjelda (negativ
 * transaksjon), innbetalinger reduserer den (positiv transaksjon). All
 * beregning leses direkte fra transaksjonene – ingen denormaliserte tall.
 */
class LoanService
{
    /** Maks antall måneder å simulere før vi gir opp («ikke nedbetalbar»). */
    private const MAX_PROJECTION_MONTHS = 600;

    /**
     * Poster månedlig rente på alle lånekontoer med effektiv rente satt. Renten
     * beregnes av gjelda og posteres den 1. i inneværende måned. Idempotent via
     * `external_id = loan-interest:YYYY-MM`, så en ny kjøring samme måned er trygg.
     *
     * @return int Antall renteposteringer opprettet.
     */
    public function postMonthlyInterest(?CarbonImmutable $month = null): int
    {
        $month = ($month ?? CarbonImmutable::now())->startOfMonth();
        $externalId = 'loan-interest:'.$month->format('Y-m');

        $accounts = Account::query()
            ->where('type', AccountType::Loan)
            ->where('closed', false)
            ->whereNotNull('interest_rate')
            ->withSum('transactions', 'amount')
            ->get();

        $posted = 0;

        foreach ($accounts as $account) {
            $debt = -round((float) ($account->transactions_sum_amount ?? 0), 2);

            // Nedbetalt (eller i pluss) → ingen rente.
            if ($debt <= 0) {
                continue;
            }

            $monthlyRate = $account->monthlyInterestRate();
            if ($monthlyRate === null || $monthlyRate <= 0) {
                continue;
            }

            $interest = round($debt * $monthlyRate, 2);
            if ($interest <= 0) {
                continue;
            }

            // Idempotent: hopp over hvis renten for denne måneden alt er postert.
            $alreadyPosted = Transaction::query()
                ->where('account_id', $account->id)
                ->where('external_id', $externalId)
                ->exists();

            if ($alreadyPosted) {
                continue;
            }

            $account->transactions()->create([
                'external_id' => $externalId,
                'date' => $month->toDateString(),
                'amount' => -$interest,
                'payee' => 'Renter',
                'cleared' => true,
            ]);

            $posted++;
        }

        if ($posted > 0) {
            Log::info("Posterte rente på {$posted} lånekonto(er) for {$month->format('Y-m')}.");
        }

        return $posted;
    }

    /**
     * Nedbetalingsprojeksjon: hvordan gjelda utvikler seg framover med nåværende
     * rente og en fast månedlig innbetaling = snittet av innbetalinger de siste
     * `$basisMonths` månedene (sum positive innbetalinger ÷ antall basismåneder).
     *
     * @return array{
     *     balance: float,
     *     interest_rate: ?float,
     *     monthly_rate: float,
     *     avg_payment: float,
     *     basis_months: int,
     *     payoff_month: ?string,
     *     months_to_payoff: ?int,
     *     total_interest: float,
     *     series: list<array{month: string, balance: float}>
     * }
     */
    public function projection(Account $account, int $basisMonths): array
    {
        $balance = round((float) $account->transactions()->sum('amount'), 2);
        $debt = -$balance;
        $monthlyRate = $account->monthlyInterestRate() ?? 0.0;
        $avgPayment = $this->averageMonthlyPayment($account, $basisMonths);

        $start = CarbonImmutable::now()->startOfMonth();
        $series = [['month' => $start->format('Y-m'), 'balance' => round($balance, 2)]];

        $payoffMonth = null;
        $monthsToPayoff = null;
        $totalInterest = 0.0;

        // Bare meningsfullt hvis det faktisk er gjeld igjen, og innbetalingen
        // dekker første måneds rente (ellers vokser gjelda → aldri nedbetalt).
        // Renten synker når gjelda synker, så dette er nok til å garantere payoff.
        if ($debt > 0 && $avgPayment > $debt * $monthlyRate) {
            for ($i = 1; $i <= self::MAX_PROJECTION_MONTHS; $i++) {
                $interest = $debt * $monthlyRate;
                $totalInterest += $interest;
                $debt = $debt + $interest - $avgPayment;
                $monthDate = $start->addMonths($i);

                if ($debt <= 0) {
                    $series[] = ['month' => $monthDate->format('Y-m'), 'balance' => 0.0];
                    $payoffMonth = $monthDate->format('Y-m');
                    $monthsToPayoff = $i;
                    break;
                }

                $series[] = ['month' => $monthDate->format('Y-m'), 'balance' => round(-$debt, 2)];
            }
        }

        return [
            'balance' => $balance,
            'interest_rate' => $account->interest_rate !== null ? (float) $account->interest_rate : null,
            'monthly_rate' => round($monthlyRate, 6),
            'avg_payment' => round($avgPayment, 2),
            'basis_months' => $basisMonths,
            'payoff_month' => $payoffMonth,
            'months_to_payoff' => $monthsToPayoff,
            'total_interest' => round($totalInterest, 2),
            'series' => $series,
        ];
    }

    /**
     * Snittlig månedlig innbetaling: sum av positive transaksjoner (innbetalinger)
     * de siste `$basisMonths` hele månedene før inneværende, delt på antall
     * basismåneder (måneder uten innbetaling teller som 0).
     */
    private function averageMonthlyPayment(Account $account, int $basisMonths): float
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($basisMonths);
        $end = CarbonImmutable::now()->startOfMonth()->subSecond();

        $payments = (float) $account->transactions()
            ->where('amount', '>', 0)
            ->where('is_starting_balance', false)
            ->whereBetween('date', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->sum('amount');

        return $payments / $basisMonths;
    }
}
