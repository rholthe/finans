<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function spending(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json($this->reports->spendingByCategory($from, $to));
    }

    public function incomeExpense(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json($this->reports->incomeVsExpense($from, $to));
    }

    public function categoryTrend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
        ]);

        [$from, $to] = $this->period($request);
        $category = Category::findOrFail($validated['category_id']);

        return response()->json($this->reports->categoryTrend($category, $from, $to));
    }

    public function netWorth(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json($this->reports->netWorth($from, $to));
    }

    /**
     * Valgt periode som [from, to] i «YYYY-MM». Default: siste 12 måneder.
     *
     * @return array{0: string, 1: string}
     */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m'],
            'to' => ['sometimes', 'date_format:Y-m'],
        ]);

        $to = $validated['to'] ?? CarbonImmutable::now()->format('Y-m');
        $from = $validated['from'] ?? CarbonImmutable::parse($to)->subMonths(11)->format('Y-m');

        // Bytt om hvis bruker har valgt en omvendt periode.
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }
}
