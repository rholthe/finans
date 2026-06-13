<?php

namespace App\Http\Controllers;

use App\Enums\GoalType;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GoalController extends Controller
{
    /**
     * Opprett eller oppdater målet for en kategori (ett mål per kategori).
     */
    public function upsert(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::enum(GoalType::class)],
            'target_amount' => ['required', 'numeric', 'gt:0'],
            'target_date' => [
                'nullable',
                'required_if:type,'.GoalType::TargetBalanceByDate->value,
                'date_format:Y-m',
            ],
        ]);

        $isByDate = $validated['type'] === GoalType::TargetBalanceByDate->value;

        $goal = $category->goal()->updateOrCreate([], [
            'type' => $validated['type'],
            'target_amount' => $validated['target_amount'],
            'target_date' => $isByDate ? $validated['target_date'].'-01' : null,
        ]);

        return response()->json([
            'type' => $goal->type->value,
            'target_amount' => round((float) $goal->target_amount, 2),
            'target_date' => $goal->target_date?->toDateString(),
        ], 200);
    }

    public function destroy(Category $category): JsonResponse
    {
        $category->goal()->delete();

        return response()->json(status: 204);
    }
}
