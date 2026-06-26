<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rapportaggregeringer. Alt beregnes direkte fra `transactions` (ingen
 * denormaliserte tall), på samme måte som BudgetService. Perioder oppgis som
 * «YYYY-MM» og tolkes inklusivt fra start av første til slutt av siste måned.
 *
 * Overføringer (`transfer_id`) nuller seg ut og holdes utenfor inntekt/forbruk;
 * forbruk teller kun kategoriserte poster på budsjettkontoer.
 */
class ReportService
{
    /**
     * Forbruk per kategorigruppe og kategori i perioden. Kun negativ aktivitet
     * (forbruk) tas med, og returneres som positive tall.
     *
     * @return array{from: string, to: string, total: float, groups: list<array<string, mixed>>}
     */
    public function spendingByCategory(string $from, string $to): array
    {
        [$start, $end] = $this->range($from, $to);

        $totals = Transaction::categoryActivity($start->toDateString(), $end->toDateString())
            ->where('on_budget', true)
            ->where('amount', '<', 0)
            ->groupBy('category_id')
            ->selectRaw('category_id, SUM(amount) as total')
            ->pluck('total', 'category_id');

        $groups = CategoryGroup::query()
            ->with('categories')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (CategoryGroup $group) use ($totals): array {
                $categories = $group->categories
                    ->map(fn (Category $category): array => [
                        'id' => $category->id,
                        'name' => $category->name,
                        'total' => round(abs((float) ($totals[$category->id] ?? 0)), 2),
                    ])
                    ->filter(fn (array $c): bool => $c['total'] > 0)
                    ->sortByDesc('total')
                    ->values()
                    ->all();

                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'total' => round(array_sum(array_column($categories, 'total')), 2),
                    'categories' => $categories,
                ];
            })
            ->filter(fn (array $g): bool => $g['total'] > 0)
            ->sortByDesc('total')
            ->values()
            ->all();

        return [
            'from' => $start->format('Y-m'),
            'to' => $end->format('Y-m'),
            'total' => round(array_sum(array_column($groups, 'total')), 2),
            'groups' => $groups,
        ];
    }

    /**
     * Inntekt vs. forbruk per måned. Inntekt = ukategorisert innflyt på
     * budsjettkontoer (ekskl. overføringer og startsaldo); forbruk = kategorisert
     * forbruk (positivt tall). net = inntekt − forbruk.
     *
     * @return array{from: string, to: string, months: list<array{month: string, income: float, expense: float, net: float}>}
     */
    public function incomeVsExpense(string $from, string $to): array
    {
        [$start, $end] = $this->range($from, $to);

        $income = Transaction::query()
            ->whereNull('category_id')
            ->where('is_split', false)
            ->whereNull('transfer_id')
            ->where('is_starting_balance', false)
            ->whereHas('account', fn ($q) => $q->where('on_budget', true))
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('amount', '>', 0)
            ->selectRaw($this->monthExpr().' as ym, SUM(amount) as total')
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $expense = Transaction::categoryActivity($start->toDateString(), $end->toDateString())
            ->where('on_budget', true)
            ->where('amount', '<', 0)
            ->selectRaw($this->monthExpr().' as ym, SUM(amount) as total')
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $months = [];
        foreach ($this->months($start, $end) as $ym) {
            $in = round((float) ($income[$ym] ?? 0), 2);
            $out = round(abs((float) ($expense[$ym] ?? 0)), 2);
            $months[] = [
                'month' => $ym,
                'income' => $in,
                'expense' => $out,
                'net' => round($in - $out, 2),
            ];
        }

        return ['from' => $start->format('Y-m'), 'to' => $end->format('Y-m'), 'months' => $months];
    }

    /**
     * Månedlig forbruk for én kategori (positive tall).
     *
     * @return array{category: array{id: int, name: string}, from: string, to: string, months: list<array{month: string, total: float}>}
     */
    public function categoryTrend(Category $category, string $from, string $to): array
    {
        [$start, $end] = $this->range($from, $to);

        $byMonth = Transaction::categoryActivity($start->toDateString(), $end->toDateString())
            ->where('on_budget', true)
            ->where('category_id', $category->id)
            ->where('amount', '<', 0)
            ->selectRaw($this->monthExpr().' as ym, SUM(amount) as total')
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $months = [];
        foreach ($this->months($start, $end) as $ym) {
            $months[] = ['month' => $ym, 'total' => round(abs((float) ($byMonth[$ym] ?? 0)), 2)];
        }

        return [
            'category' => ['id' => $category->id, 'name' => $category->name],
            'from' => $start->format('Y-m'),
            'to' => $end->format('Y-m'),
            'months' => $months,
        ];
    }

    /**
     * Nettoformue ved slutten av hver måned: saldo på alle kontoer (inkl.
     * lån/overvåkede). assets = sum positive kontosaldoer, debt = sum negative
     * (positivt tall), net = total.
     *
     * @return array{from: string, to: string, months: list<array{month: string, assets: float, debt: float, net: float}>}
     */
    public function netWorth(string $from, string $to): array
    {
        [$start, $end] = $this->range($from, $to);

        $accounts = Account::query()->get(['id']);

        // Kumulativ saldo per konto t.o.m. hver måned, bygget fra månedlige deltaer.
        $deltas = Transaction::query()
            ->whereBetween('date', [CarbonImmutable::create(1900)->toDateString(), $end->toDateString()])
            ->selectRaw('account_id, '.$this->monthExpr().' as ym, SUM(amount) as total')
            ->groupBy('account_id', 'ym')
            ->get();

        // account_id => [ym => delta]
        $byAccount = [];
        foreach ($deltas as $row) {
            $byAccount[$row->account_id][$row->ym] = (float) $row->total;
        }

        $months = [];
        foreach ($this->months($start, $end) as $ym) {
            $assets = 0.0;
            $debt = 0.0;
            foreach ($accounts as $account) {
                $balance = 0.0;
                foreach ($byAccount[$account->id] ?? [] as $monthKey => $delta) {
                    if ($monthKey <= $ym) {
                        $balance += $delta;
                    }
                }
                if ($balance >= 0) {
                    $assets += $balance;
                } else {
                    $debt += -$balance;
                }
            }
            $months[] = [
                'month' => $ym,
                'assets' => round($assets, 2),
                'debt' => round($debt, 2),
                'net' => round($assets - $debt, 2),
            ];
        }

        return ['from' => $start->format('Y-m'), 'to' => $end->format('Y-m'), 'months' => $months];
    }

    /**
     * Age of Money: beløpsvektet alder (i dager) på pengene som brukes. Hver
     * utstrøm matches FIFO mot de eldste innstrømmene; alderen er antall dager
     * fra penger kom inn til de ble brukt. Age of Money for en måned er snittet
     * av de **siste 10 utstrømmene** t.o.m. månedsslutt (samme tilnærming som YNAB).
     *
     * Grunnlag: kontantstrøm på budsjettkontoer, ekskl. overføringer (interne) og
     * reserverte (kortlevde). Positiv startsaldo teller som den eldste innstrømmen.
     * FIFO går over hele historikken (også før perioden), så innstrøm som finansierer
     * forbruk i perioden teller med.
     *
     * @return array{from: string, to: string, current: ?int, months: list<array{month: string, age: ?int}>}
     */
    public function ageOfMoney(string $from, string $to): array
    {
        [$start, $end] = $this->range($from, $to);

        $rows = Transaction::query()
            ->whereHas('account', fn ($q) => $q->where('on_budget', true))
            ->whereNull('transfer_id')
            ->where('pending', false)
            ->where('date', '<=', $end->toDateTimeString())
            ->orderBy('date')
            ->orderBy('id')
            ->get(['date', 'amount']);

        // Del kontantstrømmen i FIFO-lotter av innstrøm og en liste av utstrøm.
        $inflows = [];
        $outflows = [];
        foreach ($rows as $row) {
            $amount = (float) $row->amount;
            $date = CarbonImmutable::parse($row->date);
            if ($amount > 0) {
                $inflows[] = ['date' => $date, 'remaining' => $amount];
            } elseif ($amount < 0) {
                $outflows[] = ['date' => $date, 'amount' => -$amount];
            }
        }

        // Matche hver utstrøm mot de eldste gjenværende innstrømmene. Alderen
        // beløpsvektes; en utstrøm uten dekkende innstrøm (eller dekket av senere
        // innstrøm) teller som 0 dager (negativ alder klemmes til 0).
        $i = 0;
        $aged = [];
        foreach ($outflows as $out) {
            $need = $out['amount'];
            $weighted = 0.0;

            while ($need > 0.0001 && $i < count($inflows)) {
                $take = min($need, $inflows[$i]['remaining']);
                $ageDays = max(0, (int) round(($out['date']->timestamp - $inflows[$i]['date']->timestamp) / 86400));

                $weighted += $take * $ageDays;
                $need -= $take;
                $inflows[$i]['remaining'] -= $take;

                if ($inflows[$i]['remaining'] <= 0.0001) {
                    $i++;
                }
            }

            $aged[] = ['date' => $out['date'], 'age' => $weighted / $out['amount']];
        }

        $months = [];
        foreach ($this->months($start, $end) as $ym) {
            $monthEnd = CarbonImmutable::parse($ym)->endOfMonth();

            $upTo = array_values(array_filter($aged, fn (array $a): bool => $a['date']->lte($monthEnd)));
            $last10 = array_slice($upTo, -10);

            $months[] = [
                'month' => $ym,
                'age' => $last10 === []
                    ? null
                    : (int) round(array_sum(array_column($last10, 'age')) / count($last10)),
            ];
        }

        return [
            'from' => $start->format('Y-m'),
            'to' => $end->format('Y-m'),
            'current' => $months === [] ? null : end($months)['age'],
            'months' => $months,
        ];
    }

    /**
     * DB-agnostisk uttrykk som trekker ut «YYYY-MM» fra dato-kolonnen.
     * SQLite (lokalt) bruker strftime; MySQL/MariaDB (prod) bruker DATE_FORMAT.
     */
    private function monthExpr(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', date)",
            default => "DATE_FORMAT(date, '%Y-%m')",
        };
    }

    /**
     * Liste av «YYYY-MM» fra start til og med slutt.
     *
     * @return list<string>
     */
    private function months(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $months = [];
        $cursor = $start;
        while ($cursor->lte($end)) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    /**
     * Normaliser et periodepar til [start av første måned, slutt av siste måned].
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(string $from, string $to): array
    {
        return [
            CarbonImmutable::parse($from)->startOfMonth(),
            CarbonImmutable::parse($to)->endOfMonth(),
        ];
    }
}
