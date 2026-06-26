<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Services\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LoanController extends Controller
{
    public function __construct(private readonly LoanService $loans) {}

    /**
     * Nedbetalingsprojeksjon for en lånekonto basert på effektiv rente og
     * gjennomsnittlig innbetaling siste 3/6/12 måneder.
     */
    public function projection(Request $request, Account $account): JsonResponse
    {
        if ($account->type !== AccountType::Loan) {
            return response()->json(['message' => 'Projeksjon er kun tilgjengelig for lånekontoer.'], 422);
        }

        $validated = $request->validate([
            'basis' => ['sometimes', Rule::in([3, 6, 12])],
        ]);

        $basis = (int) ($validated['basis'] ?? 6);

        return response()->json($this->loans->projection($account, $basis));
    }
}
