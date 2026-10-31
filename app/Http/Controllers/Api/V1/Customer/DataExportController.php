<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\AddressResource;
use App\Http\Resources\CartResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\OwnReviewResource;
use App\Http\Resources\ReturnResource;
use App\Http\Resources\StockAlertResource;
use App\Http\Resources\StoreCreditResource;
use App\Models\Coupon;
use App\Models\NewsletterSubscriber;
use App\Models\StockAlert;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Download my data": everything the customer can see about themselves in their account, in one JSON file.
 * It reuses the same resources as the account screens, so nothing internal (password hash, tokens, staff notes and
 * tags, other customers) is ever included.
 */
class DataExportController extends Controller
{
    public function __invoke(Request $request, CartService $carts): JsonResponse
    {
        $customer = $request->user();
        $email = Str::lower(trim((string) $customer->email));
        $subscriber = NewsletterSubscriber::where('customer_id', $customer->id)
            ->when($email !== '', fn ($q) => $q->orWhereRaw('LOWER(email) = ?', [$email]))
            ->first();

        $data = [
            'exported_at' => now()->toIso8601String(),
            'profile' => (new CustomerResource($customer))->resolve($request),
            'addresses' => AddressResource::collection($customer->addresses()->orderBy('id')->get())->resolve($request),
            'cart' => (new CartResource($carts->summary($customer)))->resolve($request),
            'wishlist' => $customer->wishlistBooks()->withTrashed()->orderBy('wishlists.created_at')->get()
                ->map(fn ($book) => [
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'status' => $book->pivot->status,
                    'added_at' => $book->pivot->created_at,
                ])->all(),
            'orders' => OrderResource::collection(
                $customer->orders()->with(['items.variant.book' => fn ($q) => $q->withTrashed(), 'payments', 'coupon', 'statusHistory'])
                    ->orderBy('placed_at')->orderBy('id')->get()
            )->resolve($request),
            'returns' => ReturnResource::collection(
                $customer->returns()->with(ReturnController::relations())->orderBy('requested_at')->orderBy('id')->get()
            )->resolve($request),
            'reviews' => OwnReviewResource::collection(
                $customer->reviews()->with(['customer', 'book' => fn ($q) => $q->withTrashed()])->orderBy('id')->get()
            )->resolve($request),
            'stock_alerts' => StockAlertResource::collection(
                StockAlert::where('customer_id', $customer->id)->with(['bookVariant.book'])->orderBy('id')->get()
            )->resolve($request),
            'store_credits' => StoreCreditResource::collection(
                Coupon::where('customer_id', $customer->id)->orderBy('id')->get()
            )->resolve($request),
            'newsletter' => $subscriber
                ? ['status' => $subscriber->status, 'locale' => $subscriber->locale]
                : ['status' => 'none'],
        ];

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="bookly-my-data-'.now()->toDateString().'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
