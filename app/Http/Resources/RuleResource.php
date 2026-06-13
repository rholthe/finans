<?php

namespace App\Http\Resources;

use App\Models\Rule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rule
 */
class RuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'priority' => $this->priority,
            'active' => $this->active,
            'match_contains' => $this->match_contains,
            'match_not_contains' => $this->match_not_contains,
            'applies_to' => $this->applies_to->value,
            'set_payee' => $this->set_payee,
            'set_memo' => $this->set_memo,
            'category_id' => $this->category_id,
            'last_applied_at' => $this->last_applied_at?->toIso8601String(),
        ];
    }
}
