<?php

namespace App\Services;

use App\Models\BudgetAllocation;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Goal;
use App\Models\ScheduledTransaction;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Budsjettmotoren. «available» lagres aldri – den beregnes kumulativt fra
 * tildelinger (budget_allocations) + aktivitet (kategoriserte transaksjoner på
 * budsjettkontoer):
 *
 *   available(t.o.m. måned) = Σ assigned(måned' ≤ måned) + Σ activity(dato ≤ slutt av måned)
 *
 * Slik blir tallene alltid korrekte selv om en gammel transaksjon redigeres –
 * det finnes ingen denormalisert verdi som kan drifte.
 */
class BudgetService
{
    /**
     * Full budsjettvisning for én måned: grupper → kategorier med
     * assigned/activity/available, samt Ready to Assign.
     *
     * @return array{month: string, ready_to_assign: float, upcoming_income: float, projected_ready_to_assign: float, groups: list<array<string, mixed>>}
     */
    public function monthlyView(string $month): array
    {
        $start = $this->normalizeMonth($month);
        $end = $start->endOfMonth();

        $assignedThisMonth = $this->assignedByCategory(equalTo: $start);
        $assignedCumulative = $this->assignedByCategory(upTo: $start);
        $activityThisMonth = $this->activityByCategory(from: $start, to: $end);
        $activityCumulative = $this->activityByCategory(to: $end);

        [$upcomingByCategory, $upcomingIncome] = $this->upcomingForMonth($start, $end);

        $groups = CategoryGroup::query()
            ->with('categories.goal')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (CategoryGroup $group) use ($start, $assignedThisMonth, $assignedCumulative, $activityThisMonth, $activityCumulative, $upcomingByCategory): array {
                $categories = $group->categories->map(function (Category $category) use ($start, $assignedThisMonth, $assignedCumulative, $activityThisMonth, $activityCumulative, $upcomingByCategory): array {
                    $assigned = (float) ($assignedThisMonth[$category->id] ?? 0);
                    $activity = (float) ($activityThisMonth[$category->id] ?? 0);
                    $available = (float) ($assignedCumulative[$category->id] ?? 0) + (float) ($activityCumulative[$category->id] ?? 0);
                    $upcoming = (float) ($upcomingByCategory[$category->id] ?? 0);
                    $month = $start->format('Y-m');

                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                        'assigned' => round($assigned, 2),
                        'activity' => round($activity, 2),
                        'available' => round($available, 2),
                        'upcoming' => round($upcoming, 2),
                        'projected_available' => round($available + $upcoming, 2),
                        'goal' => $this->goalPayload($category->goal),
                        'needed' => $category->goal
                            ? $category->goal->neededThisMonth($month, $assigned, $available)
                            : 0.0,
                    ];
                })->all();

                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'assigned' => round(array_sum(array_column($categories, 'assigned')), 2),
                    'activity' => round(array_sum(array_column($categories, 'activity')), 2),
                    'available' => round(array_sum(array_column($categories, 'available')), 2),
                    'upcoming' => round(array_sum(array_column($categories, 'upcoming')), 2),
                    'projected_available' => round(array_sum(array_column($categories, 'projected_available')), 2),
                    'categories' => $categories,
                ];
            })
            ->all();

        $readyToAssign = $this->readyToAssign($start, $end);

        return [
            'month' => $start->format('Y-m'),
            'ready_to_assign' => $readyToAssign,
            'upcoming_income' => round($upcomingIncome, 2),
            'projected_ready_to_assign' => round($readyToAssign + $upcomingIncome, 2),
            'groups' => $groups,
        ];
    }

    /**
     * Kommende (ennå ikke posterte) planlagte poster i måneden, summert per
     * kategori. Ukategoriserte poster på budsjettkontoer (typisk inntekt)
     * returneres separat siden de påvirker Ready to Assign, ikke en kategori.
     *
     * @return array{0: array<int, float>, 1: float} [per kategori, ukategorisert netto]
     */
    private function upcomingForMonth(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $byCategory = [];
        $uncategorized = 0.0;

        $schedules = ScheduledTransaction::query()
            ->whereHas('account', fn ($q) => $q->where('on_budget', true))
            ->get();

        foreach ($schedules as $schedule) {
            $count = count($schedule->occurrencesBetween($start, $end));
            if ($count === 0) {
                continue;
            }

            $total = (float) $schedule->amount * $count;

            if ($schedule->category_id) {
                $byCategory[$schedule->category_id] = ($byCategory[$schedule->category_id] ?? 0) + $total;
            } else {
                $uncategorized += $total;
            }
        }

        return [$byCategory, $uncategorized];
    }

    /**
     * Serialiser et mål for budsjettvisningen, eller null hvis kategorien
     * ikke har et mål.
     *
     * @return array{type: string, target_amount: float, target_date: ?string}|null
     */
    private function goalPayload(?Goal $goal): ?array
    {
        if (! $goal) {
            return null;
        }

        return [
            'type' => $goal->type->value,
            'target_amount' => round((float) $goal->target_amount, 2),
            'target_date' => $goal->target_date?->toDateString(),
        ];
    }

    /**
     * Sett (eller nullstill) tildelt beløp for en kategori i en gitt måned.
     */
    public function assign(Category $category, string $month, float $amount): BudgetAllocation
    {
        $start = $this->normalizeMonth($month);

        return BudgetAllocation::updateOrCreate(
            ['category_id' => $category->id, 'month' => $start->toDateString()],
            ['assigned' => $amount],
        );
    }

    /**
     * Penger som venter på å bli fordelt = inntekter inn på budsjettkontoer
     * (t.o.m. måneden) minus alt som er tildelt (t.o.m. måneden).
     *
     * Kun *ukategoriserte* transaksjoner (inntekt/innskudd) teller som tilflyt til
     * RTA. Kategorisert forbruk hører hjemme i kategoriens «available», ikke her –
     * teller vi det med, trekkes forbruket fra to ganger og pengene «lekker» ut av
     * regnskapet (identiteten RTA + Σtilgjengelig = penger på konto brytes).
     */
    private function readyToAssign(CarbonImmutable $start, CarbonImmutable $end): float
    {
        $inBudget = (float) Transaction::query()
            ->whereHas('account', fn ($q) => $q->where('on_budget', true))
            ->whereNull('category_id')
            ->where('date', '<=', $end->toDateString())
            ->sum('amount');

        $assigned = (float) BudgetAllocation::query()
            ->where('month', '<=', $start->toDateString())
            ->sum('assigned');

        return round($inBudget - $assigned, 2);
    }

    /**
     * Sum tildelt per kategori. Enten for én bestemt måned (equalTo) eller
     * kumulativt t.o.m. en måned (upTo).
     *
     * @return Collection<int, float>
     */
    private function assignedByCategory(?CarbonImmutable $equalTo = null, ?CarbonImmutable $upTo = null): Collection
    {
        return BudgetAllocation::query()
            ->when($equalTo, fn ($q) => $q->where('month', $equalTo->toDateString()))
            ->when($upTo, fn ($q) => $q->where('month', '<=', $upTo->toDateString()))
            ->groupBy('category_id')
            ->selectRaw('category_id, SUM(assigned) as total')
            ->pluck('total', 'category_id')
            ->map(fn ($total): float => (float) $total);
    }

    /**
     * Sum aktivitet (kategoriserte transaksjoner på budsjettkontoer) per
     * kategori, eventuelt avgrenset til et datointervall.
     *
     * @return Collection<int, float>
     */
    private function activityByCategory(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Collection
    {
        return Transaction::query()
            ->whereNotNull('category_id')
            ->whereHas('account', fn ($q) => $q->where('on_budget', true))
            ->when($from, fn ($q) => $q->where('date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('date', '<=', $to->toDateString()))
            ->groupBy('category_id')
            ->selectRaw('category_id, SUM(amount) as total')
            ->pluck('total', 'category_id')
            ->map(fn ($total): float => (float) $total);
    }

    /**
     * Tolk «YYYY-MM» (eller en hvilken som helst dato) som den 1. i måneden.
     */
    private function normalizeMonth(string $month): CarbonImmutable
    {
        return CarbonImmutable::parse($month)->startOfMonth();
    }
}
