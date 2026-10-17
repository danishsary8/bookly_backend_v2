<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookResource;
use App\Models\Wishlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class WishlistController extends Controller
{
    /** Saved books, newest first. Filterable by reading status. Books that are currently unavailable stay listed with in_stock=false. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(Wishlist::STATUSES)],
        ]);

        $user = $request->user();

        $query = $user->wishlistBooks()
            ->where(function ($q) {
                $q->where('books.status', 'live')
                    ->orWhere(fn ($sub) => $sub->where('books.status', 'scheduled')->where('books.publish_at', '<=', now()));
            })
            ->with(['authors', 'activeVariants'])
            ->withMin('activeVariants as min_price', 'price_usd')
            ->withAvg('approvedReviews as rating_avg', 'rating')
            ->withCount('approvedReviews as review_count')
            ->orderByPivot('created_at', 'desc');

        if (! empty($data['status'])) {
            $query->wherePivot('status', $data['status']);
        }

        $paginated = $query->paginate($data['per_page'] ?? 20)->withQueryString();

        $liveBooksConstraint = function ($q) {
            $q->where('books.status', 'live')
                ->orWhere(fn ($sub) => $sub->where('books.status', 'scheduled')->where('books.publish_at', '<=', now()));
        };

        return BookResource::collection($paginated)->additional([
            'meta' => [
                'counts' => [
                    'all' => $user->wishlistBooks()->where($liveBooksConstraint)->count(),
                    Wishlist::STATUS_WANT_TO_READ => $user->wishlistBooks()->where($liveBooksConstraint)->wherePivot('status', Wishlist::STATUS_WANT_TO_READ)->count(),
                    Wishlist::STATUS_READING => $user->wishlistBooks()->where($liveBooksConstraint)->wherePivot('status', Wishlist::STATUS_READING)->count(),
                    Wishlist::STATUS_FINISHED => $user->wishlistBooks()->where($liveBooksConstraint)->wherePivot('status', Wishlist::STATUS_FINISHED)->count(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'book_id' => [
                'required',
                'integer',
                Rule::exists('books', 'id')
                    ->whereNull('deleted_at')
                    ->where(function ($q) {
                        $q->where('status', 'live')
                            ->orWhere(fn ($sub) => $sub->where('status', 'scheduled')->where('publish_at', '<=', now()));
                    }),
            ],
            'status' => ['nullable', 'string', Rule::in(Wishlist::STATUSES)],
        ]);

        $status = $data['status'] ?? Wishlist::STATUS_WANT_TO_READ;

        $result = $request->user()->wishlistBooks()->syncWithoutDetaching([
            $data['book_id'] => [
                'status' => $status,
                'created_at' => now(),
            ],
        ]);
        $added = $result['attached'] !== [];

        if (! $added && isset($data['status'])) {
            $request->user()->wishlistBooks()->updateExistingPivot($data['book_id'], ['status' => $status]);
        }

        return response()->json(
            [
                'message' => $added ? 'Added to your wishlist.' : 'Already in your wishlist.',
                'book_id' => $data['book_id'],
                'status' => $status,
            ],
            $added ? 201 : 200,
        );
    }

    public function update(Request $request, int $book): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(Wishlist::STATUSES)],
        ]);

        $exists = $request->user()->wishlistBooks()->where('books.id', $book)->exists();
        if (! $exists) {
            return response()->json(['message' => 'Book not found in your wishlist.'], 404);
        }

        $request->user()->wishlistBooks()->updateExistingPivot($book, [
            'status' => $data['status'],
        ]);

        return response()->json([
            'message' => 'Wishlist status updated.',
            'book_id' => $book,
            'status' => $data['status'],
        ]);
    }

    public function destroy(Request $request, int $book): JsonResponse
    {
        $request->user()->wishlistBooks()->detach($book);

        return response()->json(null, 204);
    }
}
