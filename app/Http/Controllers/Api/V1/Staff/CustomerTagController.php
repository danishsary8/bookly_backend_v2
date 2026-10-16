<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerTag;
use App\Services\Admin\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerTagController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function suggestions(Request $request): JsonResponse
    {
        $q = $request->query('q');
        $term = $q !== null && trim($q) !== '' ? '%'.addcslashes(trim($q), '%_').'%' : null;

        $tags = CustomerTag::query()
            ->has('customers')
            ->withCount('customers')
            ->when($term, fn ($query) => $query->where('name', 'ilike', $term))
            ->orderByDesc('customers_count')
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $tags->map(fn ($tag) => [
                'name' => $tag->name,
                'count' => $tag->customers_count,
                'customer_count' => $tag->customers_count,
            ]),
        ]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'tags' => ['present', 'array', 'max:10'],
            'tags.*' => ['required', 'string', 'max:40'],
        ]);

        $cleanTagNames = [];
        foreach ($data['tags'] as $rawTag) {
            $collapsed = preg_replace('/\s+/', ' ', trim($rawTag));
            if ($collapsed === '' || mb_strlen($collapsed) > 40) {
                continue;
            }
            $lower = mb_strtolower($collapsed);
            if (! isset($cleanTagNames[$lower])) {
                $cleanTagNames[$lower] = $collapsed;
            }
        }

        if (count($cleanTagNames) > 10) {
            return response()->json(['message' => 'A customer can have at most 10 tags.'], 422);
        }

        $tagIds = [];
        foreach ($cleanTagNames as $lower => $name) {
            // Matching is case-insensitive and reuses the existing tag's spelling
            $tag = CustomerTag::whereRaw('LOWER(name) = ?', [$lower])->first();
            if (! $tag) {
                try {
                    $tag = CustomerTag::create(['name' => $name]);
                } catch (UniqueConstraintViolationException) { // made at the same moment by another request
                    $tag = CustomerTag::whereRaw('LOWER(name) = ?', [$lower])->firstOrFail();
                }
            }
            $tagIds[] = $tag->id;
        }

        $oldTags = $customer->tags()->pluck('name')->values()->all();
        $customer->tags()->sync($tagIds);
        $newTags = $customer->tags()->pluck('name')->values()->all();

        $this->audit->custom(
            $request->user(),
            'updated_tags',
            $customer,
            ['tags' => $oldTags],
            ['tags' => $newTags]
        );

        return response()->json(['tags' => $newTags]);
    }
}
