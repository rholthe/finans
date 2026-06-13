<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $budget) {}

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
}
