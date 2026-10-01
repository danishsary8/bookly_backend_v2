<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OwnReviewResource;
use App\Models\Book;
use App\Models\OrderItem;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReviewController extends Controller
{
    /** The customer's own reviews, including ones staff have hidden. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return OwnReviewResource::collection(
            $request->user()->reviews()->with(['customer', 'book' => fn ($q) => $q->withTrashed()])
                ->latest()->orderByDesc('id')->paginate($data['per_page'] ?? 20)->withQueryString()
        );
    }

    public function store(Request $request, Book $book): JsonResponse
    {
        $data = $this->validated($request);
        $customer = $request->user();

        // Verified purchase: the book (any format) was in one of the customer's delivered orders.
        $orderItemId = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('customer_id', $customer->id)->where('status', OrderStatus::Delivered))
            ->whereHas('variant', fn ($q) => $q->where('book_id', $book->id))
            ->latest('id')
            ->value('id');

        if ($orderItemId === null) {
            return response()->json(['message' => 'You can review a book once an order containing it has been delivered.'], 403);
        }

        if ($customer->reviews()->where('book_id', $book->id)->exists()) {
            return response()->json(['message' => 'You have already reviewed this book. Edit your existing review instead.'], 409);
        }

        $review = $customer->reviews()->create([...$data, 'book_id' => $book->id, 'order_item_id' => $orderItemId]);

        return (new OwnReviewResource($review->refresh()->load(['customer', 'book'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $review): OwnReviewResource
    {
        $model = $request->user()->reviews()->findOrFail($review);
        // Editing never un-hides a review that staff have hidden.
        $model->update($this->validated($request, partial: true));

        return new OwnReviewResource($model->load(['customer', 'book' => fn ($q) => $q->withTrashed()]));
    }

    public function destroy(Request $request, int $review): JsonResponse
    {
        $request->user()->reviews()->findOrFail($review)->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'rating' => [$partial ? 'sometimes' : 'required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
