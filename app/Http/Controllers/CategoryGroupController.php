<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryGroupResource;
use App\Models\CategoryGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryGroupController extends Controller
{
    /**
     * Alle kategorigrupper med kategoriene sine.
     */
    public function index(): AnonymousResourceCollection
    {
        $groups = CategoryGroup::query()
            ->with('categories')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return CategoryGroupResource::collection($groups);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        $group = CategoryGroup::create($validated);

        return CategoryGroupResource::make($group)
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, CategoryGroup $categoryGroup): CategoryGroupResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        $categoryGroup->update($validated);

        return CategoryGroupResource::make($categoryGroup);
    }

    public function destroy(CategoryGroup $categoryGroup): JsonResponse
    {
        $categoryGroup->delete();

        return response()->json(status: 204);
    }
}
