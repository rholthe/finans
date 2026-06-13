<?php

namespace App\Services;

use App\Models\Category;

/**
 * Auto-allokering: fordeler «Ready to Assign» til kategorier basert på mål
 * eller for å dekke overtrekk. Skriver via BudgetService::assign og returnerer
 * en oppdatert månedsvisning.
 */
class GoalService
{
    public const STRATEGY_FUND_GOALS = 'fund-goals';

    public const STRATEGY_COVER_OVERSPENDING = 'cover-overspending';

    public function __construct(private readonly BudgetService $budget) {}

    /**
     * Fordel tilgjengelige midler etter valgt strategi, i visningsrekkefølge,
     * begrenset av hvor mye som er klart til å fordeles.
     *
     * @return array<string, mixed>
     */
    public function autoAssign(string $month, string $strategy): array
    {
        $view = $this->budget->monthlyView($month);
        $remaining = (float) $view['ready_to_assign'];

        foreach ($view['groups'] as $group) {
            foreach ($group['categories'] as $category) {
                if ($remaining <= 0) {
                    break 2;
                }

                // Dekk overtrekk bruker projisert tilgjengelig, slik at også
                // kommende (ikke-posterte) regninger i måneden dekkes opp.
                $wanted = $strategy === self::STRATEGY_COVER_OVERSPENDING
                    ? max(0, -$category['projected_available'])
                    : ($category['goal'] ? $category['needed'] : 0);

                $amount = round(min($wanted, $remaining), 2);
                if ($amount <= 0) {
                    continue;
                }

                $this->budget->assign(
                    Category::findOrFail($category['id']),
                    $month,
                    round($category['assigned'] + $amount, 2),
                );
                $remaining -= $amount;
            }
        }

        return $this->budget->monthlyView($month);
    }

    /**
     * Fyll én kategori opp til målet for måneden (tildel det som mangler).
     *
     * @return array<string, mixed>
     */
    public function fundCategory(Category $category, string $month): array
    {
        $view = $this->budget->monthlyView($month);

        foreach ($view['groups'] as $group) {
            foreach ($group['categories'] as $current) {
                if ($current['id'] === $category->id && $current['needed'] > 0) {
                    $this->budget->assign(
                        $category,
                        $month,
                        round($current['assigned'] + $current['needed'], 2),
                    );
                    break 2;
                }
            }
        }

        return $this->budget->monthlyView($month);
    }
}
