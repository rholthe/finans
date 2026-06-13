<?php

namespace App\Http\Resources;

use App\Models\CategoryGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CategoryGroup
 */
class CategoryGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
        ];
    }
}
