<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\BudgetService;
use App\Services\GoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetService $budget,
        private readonly GoalService $goals,
    ) {}

    /**
     * Budsjettvisning for en måned (?month=YYYY-MM, default inneværende måned).
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['sometimes', 'date_format:Y-m'],
        ]);

        $month = $validated['month'] ?? now()->format('Y-m');

        return response()->json($this->budget->monthlyView($month));
    }

    /**
     * Sett tildelt beløp for en kategori i en gitt måned.
     */
    public function assign(Request $request, string $month, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'assigned' => ['required', 'numeric'],
        ]);

        $this->budget->assign($category, $month, (float) $validated['assigned']);

        return response()->json($this->budget->monthlyView($month));
    }

    /**
     * Auto-allokering: fyll opp mål eller dekk overtrekk fra Ready to Assign.
     */
    public function autoAssign(Request $request, string $month): JsonResponse
    {
        $validated = $request->validate([
            'strategy' => [
                'sometimes',
                Rule::in([GoalService::STRATEGY_FUND_GOALS, GoalService::STRATEGY_COVER_OVERSPENDING]),
            ],
        ]);

        $strategy = $validated['strategy'] ?? GoalService::STRATEGY_FUND_GOALS;

        return response()->json($this->goals->autoAssign($month, $strategy));
    }

    /**
     * Fyll én kategori opp til målet for måneden.
     */
    public function fundCategory(string $month, Category $category): JsonResponse
    {
        return response()->json($this->goals->fundCategory($category, $month));
    }
}
