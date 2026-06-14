<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ScheduledTransaction;
use App\Models\Transaction;
use App\Services\BudgetService;
use App\Services\GoalService;
use Carbon\CarbonImmutable;
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
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', Rule::exists('categories', 'id')],
        ]);

        $strategy = $validated['strategy'] ?? GoalService::STRATEGY_FUND_GOALS;

        return response()->json(
            $this->goals->autoAssign($month, $strategy, $validated['category_ids'] ?? null),
        );
    }

    /**
     * Tøm alt tilgjengelig fra et utvalg kategorier over i én målkategori.
     */
    public function sweep(Request $request, string $month): JsonResponse
    {
        $validated = $request->validate([
            'to_category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'from_category_ids' => ['required', 'array', 'min:1'],
            'from_category_ids.*' => ['integer', Rule::exists('categories', 'id')],
        ]);

        $target = Category::findOrFail($validated['to_category_id']);
        $this->budget->sweepToCategory($validated['from_category_ids'], $target, $month);

        return response()->json($this->budget->monthlyView($month));
    }

    /**
     * Nullstill tildelingen (assigned = 0) for et utvalg kategorier i måneden.
     */
    public function resetAssignments(Request $request, string $month): JsonResponse
    {
        $validated = $request->validate([
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', Rule::exists('categories', 'id')],
        ]);

        foreach ($validated['category_ids'] as $id) {
            $this->budget->assign(Category::findOrFail($id), $month, 0);
        }

        return response()->json($this->budget->monthlyView($month));
    }

    /**
     * Fyll én kategori opp til målet for måneden.
     */
    public function fundCategory(string $month, Category $category): JsonResponse
    {
        return response()->json($this->goals->fundCategory($category, $month));
    }

    /**
     * Detaljer bak «aktivitet» for en kategori i måneden: de faktiske
     * transaksjonene (med konto) og en oversikt over planlagte poster med forfall
     * i måneden.
     */
    public function categoryTransactions(string $month, Category $category): JsonResponse
    {
        $start = CarbonImmutable::parse($month)->startOfMonth();
        $end = $start->endOfMonth();

        $transactions = Transaction::query()
            ->with('account:id,name')
            ->where('category_id', $category->id)
            ->whereHas('account', fn ($q) => $q->where('on_budget', true))
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Transaction $t): array => [
                'id' => $t->id,
                'date' => $t->date->toDateString(),
                'amount' => round((float) $t->amount, 2),
                'payee' => $t->payee,
                'memo' => $t->memo,
                'account' => $t->account?->name,
            ])
            ->all();

        $scheduled = ScheduledTransaction::query()
            ->with('account:id,name')
            ->where('category_id', $category->id)
            ->get()
            ->map(function (ScheduledTransaction $s) use ($start, $end): ?array {
                $occurrences = $s->occurrencesBetween($start, $end);
                if (count($occurrences) === 0) {
                    return null;
                }

                return [
                    'id' => $s->id,
                    'amount' => round((float) $s->amount, 2),
                    'payee' => $s->payee,
                    'memo' => $s->memo,
                    'account' => $s->account?->name,
                    'frequency' => $s->frequency->value,
                    'dates' => array_map(fn ($d): string => $d->toDateString(), $occurrences),
                    'total' => round((float) $s->amount * count($occurrences), 2),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return response()->json([
            'category' => ['id' => $category->id, 'name' => $category->name],
            'month' => $start->format('Y-m'),
            'transactions' => $transactions,
            'scheduled' => $scheduled,
        ]);
    }

    /**
     * Flytt tilgjengelige penger fra en kategori til en annen i måneden
     * (justerer tildelingene, netto 0). Returnerer oppdatert månedsvisning.
     */
    public function move(Request $request, string $month, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'to_category_id' => [
                'required',
                'integer',
                Rule::notIn([$category->id]),
                Rule::exists('categories', 'id'),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $target = Category::findOrFail($validated['to_category_id']);

        try {
            $this->budget->move($category, $target, $month, (float) $validated['amount']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->budget->monthlyView($month));
    }
}
