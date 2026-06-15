<?php

namespace App\Http\Controllers;

use App\Enums\RuleApplies;
use App\Enums\RuleTarget;
use App\Http\Resources\RuleResource;
use App\Models\BankAccount;
use App\Models\Rule;
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
            'target_type' => ['sometimes', ValidationRule::enum(RuleTarget::class)],
            'transfer_account_id' => ['nullable', ValidationRule::exists('accounts', 'id')],
        ]);

        // Valider mål/handling kun når mål- eller handlingsfeltene er berørt.
        $touchesActions = ! $partial
            || $request->hasAny(['target_type', 'set_payee', 'set_memo', 'category_id', 'transfer_account_id']);

        if (! $touchesActions) {
            return $validated;
        }

        $target = $validated['target_type'] ?? RuleTarget::Category->value;

        if ($target === RuleTarget::Transfer->value) {
            $accountId = $validated['transfer_account_id'] ?? null;
            if (blank($accountId)) {
                throw ValidationException::withMessages([
                    'transfer_account_id' => [__('Velg en mottakerkonto for overføringsregelen.')],
                ]);
            }
            // Målkontoen må være en ikke-synket konto, ellers importerer den andre
            // siden sitt eget ben og man får dobbeltpostering.
            if (BankAccount::where('account_id', $accountId)->exists()) {
                throw ValidationException::withMessages([
                    'transfer_account_id' => [__('Overføring kan kun gå til en konto som ikke er koblet til banksynk.')],
                ]);
            }
        } elseif ($target === RuleTarget::Rta->value) {
            // RTA er i seg selv handlingen; kategori/overføring gir ikke mening.
            $validated['category_id'] = null;
            $validated['transfer_account_id'] = null;
        } else { // category
            $payee = $validated['set_payee'] ?? ($partial ? $request->input('set_payee') : null);
            $memo = $validated['set_memo'] ?? ($partial ? $request->input('set_memo') : null);
            $category = $validated['category_id'] ?? ($partial ? $request->input('category_id') : null);

            if (blank($payee) && blank($memo) && blank($category)) {
                throw ValidationException::withMessages([
                    'set_payee' => [__('En regel må sette minst én av payee, memo eller kategori.')],
                ]);
            }
            $validated['transfer_account_id'] = null;
        }

        return $validated;
    }
}
