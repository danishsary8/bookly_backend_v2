<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class WishlistController extends Controller
{
    /** Saved books, newest first. Books that are currently unavailable stay listed with in_stock=false. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return BookResource::collection(
            $request->user()->wishlistBooks()
                ->with(['authors', 'activeVariants'])
                ->withMin('activeVariants as min_price', 'price_usd')
                ->withAvg('approvedReviews as rating_avg', 'rating')
                ->withCount('approvedReviews as review_count')
                ->orderByPivot('created_at', 'desc')
                ->paginate($data['per_page'] ?? 20)
                ->withQueryString()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'book_id' => ['required', 'integer', Rule::exists('books', 'id')->whereNull('deleted_at')],
        ]);

        $result = $request->user()->wishlistBooks()->syncWithoutDetaching([
            $data['book_id'] => ['created_at' => now()],
        ]);
        $added = $result['attached'] !== [];

        return response()->json(
            ['message' => $added ? 'Added to your wishlist.' : 'Already in your wishlist.', 'book_id' => $data['book_id']],
            $added ? 201 : 200,
        );
    }

    public function destroy(Request $request, int $book): JsonResponse
    {
        $request->user()->wishlistBooks()->detach($book);

        return response()->json(null, 204);
    }
}
