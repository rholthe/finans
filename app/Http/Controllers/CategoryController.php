<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_group_id' => ['required', Rule::exists('category_groups', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer'],
            'note' => ['nullable', 'string'],
        ]);

        $category = Category::create($validated);

        return CategoryResource::make($category)
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Category $category): CategoryResource
    {
        $validated = $request->validate([
            'category_group_id' => ['sometimes', 'required', Rule::exists('category_groups', 'id')],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer'],
            'note' => ['nullable', 'string'],
        ]);

        $category->update($validated);

        return CategoryResource::make($category);
    }

    public function destroy(Category $category): JsonResponse
    {
        $category->delete();

        return response()->json(status: 204);
    }
}
