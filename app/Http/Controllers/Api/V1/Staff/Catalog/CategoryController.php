<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:120', 'alpha_dash', 'unique:categories,slug'],
        ]);
        $data['slug'] ??= $this->uniqueSlug($data['name']);

        $category = Category::create($data);
        $this->audit->created($request->user(), $category);

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(Request $request, Category $category): CategoryResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'slug' => ['sometimes', 'required', 'string', 'max:120', 'alpha_dash', Rule::unique('categories', 'slug')->ignore($category)],
        ]);

        $before = $category->getAttributes();
        $category->update($data);
        $this->audit->updated($request->user(), $category, $before);

        return new CategoryResource($category);
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $inUse = $category->books()->withTrashed()->count();
        if ($inUse > 0) {
            return response()->json(['message' => "This category is used by {$inUse} book(s). Remove it from those books first."], 409);
        }

        $category->delete();
        $this->audit->deleted($request->user(), $category);

        return response()->json(null, 204);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;

        for ($i = 2; Category::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
