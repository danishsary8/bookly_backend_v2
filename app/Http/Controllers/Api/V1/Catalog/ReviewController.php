<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Book;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Public reviews of a book, with the rating breakdown. */
class ReviewController extends Controller
{
    public function index(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'sort' => ['nullable', Rule::in(['newest', 'highest', 'lowest'])],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = $book->approvedReviews()->with('customer')
            ->when($data['rating'] ?? null, fn ($q, $rating) => $q->where('rating', $rating));

        match ($data['sort'] ?? 'newest') {
            'highest' => $query->orderByDesc('rating')->latest(),
            'lowest' => $query->orderBy('rating')->latest(),
            default => $query->latest(),
        };

        $page = $query->orderByDesc('id')->paginate($data['per_page'] ?? 10)->withQueryString();
        $counts = $book->approvedReviews()->selectRaw('rating, COUNT(*) AS n')->groupBy('rating')->pluck('n', 'rating');
        $total = (int) $counts->sum();

        return response()->json([
            'data' => ReviewResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'rating_summary' => [
                    'average' => $total > 0 ? round($counts->map(fn ($n, $r) => $n * $r)->sum() / $total, 1) : null,
                    'count' => $total,
                    'distribution' => collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($r) => [$r => (int) ($counts[$r] ?? 0)]),
                ],
            ],
        ]);
    }
}
