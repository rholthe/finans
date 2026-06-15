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
use Illuminate\Support\Facades\DB;

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
     * @return array{month: string, ready_to_assign: float, upcoming_income: float, projected_ready_to_assign: float, prior_uncategorized: int, groups: list<array<string, mixed>>}
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
            // Ukategoriserte transaksjoner datert før denne måneden – grunnlag for
            // advarselen om at historikk bør ryddes (ingen sperre).
            'prior_uncategorized' => Transaction::query()
                ->needsCategorization()
                ->where('date', '<', $start->toDateString())
                ->count(),
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

        // Hent alle (også overføringer der budsjett-benet ligger på mottakerkontoen).
        $schedules = ScheduledTransaction::with(['account', 'transferAccount'])->get();

        foreach ($schedules as $schedule) {
            $impact = $this->scheduleBudgetImpact($schedule);
            if ($impact === null) {
                continue;
            }

            $count = count($schedule->occurrencesBetween($start, $end));
            if ($count === 0) {
                continue;
            }

            [$categoryId, $perOccurrence] = $impact;
            $total = $perOccurrence * $count;

            if ($categoryId !== null) {
                $byCategory[$categoryId] = ($byCategory[$categoryId] ?? 0) + $total;
            } else {
                $uncategorized += $total;
            }
        }

        return [$byCategory, $uncategorized];
    }

    /**
     * Budsjetteffekt per forekomst av en planlagt post, eller null hvis den ikke
     * påvirker budsjettet. Returnerer [kategori-id|null (RTA), signert beløp].
     *
     * Overføringer følger samme budsjett↔overvåket-regler som TransferService:
     * budsjett↔budsjett er nøytral, budsjett→overvåket er kategorisert forbruk
     * (negativt), overvåket→budsjett er tilflyt til RTA (positivt).
     *
     * @return array{0: int|null, 1: float}|null
     */
    private function scheduleBudgetImpact(ScheduledTransaction $schedule): ?array
    {
        $amount = (float) $schedule->amount;

        if (! $schedule->isTransfer()) {
            return $schedule->account->on_budget ? [$schedule->category_id, $amount] : null;
        }

        $to = $schedule->transferAccount;
        if ($to === null) {
            return null;
        }

        $fromBudget = $schedule->account->on_budget;
        $toBudget = $to->on_budget;

        return match (true) {
            $fromBudget && ! $toBudget => [$schedule->category_id, $amount], // ut: kategorisert (amount er negativt)
            ! $fromBudget && $toBudget => [null, -$amount],                  // inn: tilflyt til RTA
            default => null,                                                 // budsjett↔budsjett / overvåket↔overvåket
        };
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
     * Tilgjengelig beløp for én kategori t.o.m. en gitt måned – samme beregning
     * som i månedsvisningen (kumulativ tildeling + kumulativ aktivitet).
     */
    public function availableForCategory(Category $category, string $month): float
    {
        $start = $this->normalizeMonth($month);
        $end = $start->endOfMonth();

        $assigned = (float) BudgetAllocation::query()
            ->where('category_id', $category->id)
            ->where('month', '<=', $start->toDateString())
            ->sum('assigned');

        $activity = (float) Transaction::query()
            ->where('category_id', $category->id)
            ->whereHas('account', fn ($q) => $q->where('on_budget', true))
            ->where('date', '<=', $end->toDateString())
            ->sum('amount');

        return round($assigned + $activity, 2);
    }

    /**
     * Flytt tilgjengelige penger fra én kategori til en annen i en gitt måned ved
     * å justere tildelingene: kilden reduseres og mottakeren økes med samme beløp.
     * Netto tildeling er 0, så Ready to Assign er uberørt. Beløpet kan ikke
     * overstige kildens tilgjengelige (den skal ikke kunne havne i minus).
     *
     * @throws \InvalidArgumentException ved ugyldig beløp
     */
    public function move(Category $from, Category $to, string $month, float $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Beløpet må være større enn 0.');
        }

        if ($from->id === $to->id) {
            throw new \InvalidArgumentException('Kan ikke flytte til samme kategori.');
        }

        $available = $this->availableForCategory($from, $month);
        if ($amount > $available + 0.001) {
            throw new \InvalidArgumentException('Beløpet kan ikke overstige tilgjengelig i kildekategorien.');
        }

        $start = $this->normalizeMonth($month);

        DB::transaction(function () use ($from, $to, $start, $month, $amount): void {
            $fromAssigned = (float) (BudgetAllocation::query()
                ->where('category_id', $from->id)
                ->where('month', $start->toDateString())
                ->value('assigned') ?? 0.0);

            $toAssigned = (float) (BudgetAllocation::query()
                ->where('category_id', $to->id)
                ->where('month', $start->toDateString())
                ->value('assigned') ?? 0.0);

            $this->assign($from, $month, round($fromAssigned - $amount, 2));
            $this->assign($to, $month, round($toAssigned + $amount, 2));
        });
    }

    /**
     * Tøm alt tilgjengelig fra et utvalg kildekategorier over i én målkategori
     * (justerer tildelingene, netto 0). Kilder uten tilgjengelig hoppes over, og
     * målkategorien kan ikke være sin egen kilde. Returnerer totalt flyttet beløp.
     *
     * @param  list<int>  $fromCategoryIds
     */
    public function sweepToCategory(array $fromCategoryIds, Category $to, string $month): float
    {
        $start = $this->normalizeMonth($month);
        $moved = 0.0;

        DB::transaction(function () use ($fromCategoryIds, $to, $start, $month, &$moved): void {
            foreach ($fromCategoryIds as $fromId) {
                if ((int) $fromId === $to->id) {
                    continue;
                }

                $from = Category::find($fromId);
                if (! $from) {
                    continue;
                }

                $available = $this->availableForCategory($from, $month);
                if ($available <= 0) {
                    continue;
                }

                $fromAssigned = (float) (BudgetAllocation::query()
                    ->where('category_id', $from->id)
                    ->where('month', $start->toDateString())
                    ->value('assigned') ?? 0.0);

                $this->assign($from, $month, round($fromAssigned - $available, 2));
                $moved += $available;
            }

            if ($moved > 0) {
                $toAssigned = (float) (BudgetAllocation::query()
                    ->where('category_id', $to->id)
                    ->where('month', $start->toDateString())
                    ->value('assigned') ?? 0.0);

                $this->assign($to, $month, round($toAssigned + $moved, 2));
            }
        });

        return round($moved, 2);
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
