<?php

namespace App\Http\Controllers;

use App\Enums\RuleApplies;
use App\Http\Resources\RuleResource;
use App\Models\Rule;
use App\Services\Rules\ReapplyRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\Validation\ValidationException;

class RuleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $rules = Rule::query()->orderBy('priority')->orderBy('id')->get();

        return RuleResource::collection($rules);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $rule = Rule::create($validated);

        return RuleResource::make($rule)->response()->setStatusCode(201);
    }

    public function update(Request $request, Rule $rule): RuleResource
    {
        $validated = $this->validatePayload($request, partial: true);

        $rule->update($validated);

        return RuleResource::make($rule);
    }

    public function destroy(Rule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json(status: 204);
    }

    /**
     * Sett ny prioritetsrekkefølge: [{id, priority}, ...].
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*.id' => ['required', ValidationRule::exists('rules', 'id')],
            'rules.*.priority' => ['required', 'integer'],
        ]);

        foreach ($validated['rules'] as $row) {
            Rule::whereKey($row['id'])->update(['priority' => $row['priority']]);
        }

        return response()->json(status: 204);
    }

    /**
     * Kjør reglene på nytt mot eksisterende bank-importerte transaksjoner.
     */
    public function reapply(ReapplyRules $service): JsonResponse
    {
        return response()->json(['updated' => $service->run()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'priority' => ['sometimes', 'integer'],
            'active' => ['sometimes', 'boolean'],
            'match_contains' => [$required, 'string'],
            'match_not_contains' => ['nullable', 'string'],
            'applies_to' => ['sometimes', ValidationRule::enum(RuleApplies::class)],
            'set_payee' => ['nullable', 'string', 'max:255'],
            'set_memo' => ['nullable', 'string'],
            'category_id' => ['nullable', ValidationRule::exists('categories', 'id')],
        ]);

        // Minst én handling må være satt (med mindre dette er en delvis oppdatering
        // som ikke rører handlingsfeltene).
        $touchesActions = ! $partial
            || $request->hasAny(['set_payee', 'set_memo', 'category_id']);

        if ($touchesActions) {
            $payee = $validated['set_payee'] ?? ($partial ? $request->input('set_payee') : null);
            $memo = $validated['set_memo'] ?? ($partial ? $request->input('set_memo') : null);
            $category = $validated['category_id'] ?? ($partial ? $request->input('category_id') : null);

            if (blank($payee) && blank($memo) && blank($category)) {
                throw ValidationException::withMessages([
                    'set_payee' => [__('En regel må sette minst én av payee, memo eller kategori.')],
                ]);
            }
        }

        return $validated;
    }
}
