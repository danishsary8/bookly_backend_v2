<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockAlertResource;
use App\Models\BookVariant;
use App\Models\StockAlert;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class StockAlertController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $alerts = StockAlert::query()
            ->where('customer_id', $request->user()->id)
            ->with(['bookVariant.book'])
            ->latest('id')
            ->get();

        return StockAlertResource::collection($alerts);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'book_variant_id' => ['required', 'integer'],
        ]);

        $variant = BookVariant::with('book')->find($data['book_variant_id']);

        if (! $variant) {
            throw ValidationException::withMessages([
                'book_variant_id' => [__('messages.stock_alert_no_format')],
            ]);
        }

        if ($variant->format->isDigital()) {
            throw ValidationException::withMessages([
                'book_variant_id' => [__('messages.stock_alert_digital')],
            ]);
        }

        if (! $variant->is_active) {
            throw ValidationException::withMessages([
                'book_variant_id' => [__('messages.stock_alert_inactive')],
            ]);
        }

        if (! $variant->book || ! $variant->book->isPublic()) {
            throw ValidationException::withMessages([
                'book_variant_id' => [__('messages.stock_alert_unavailable')],
            ]);
        }

        if ($variant->stock_quantity > 0) {
            throw ValidationException::withMessages([
                'book_variant_id' => [__('messages.stock_alert_in_stock')],
            ]);
        }

        $customer = $request->user();

        $openCount = StockAlert::where('customer_id', $customer->id)
            ->whereNull('notified_at')
            ->count();

        if ($openCount >= 50) {
            throw ValidationException::withMessages([
                'book_variant_id' => [__('messages.stock_alert_max')],
            ]);
        }

        $existing = StockAlert::where('customer_id', $customer->id)
            ->where('book_variant_id', $variant->id)
            ->whereNull('notified_at')
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'book_variant_id' => [__('messages.stock_alert_exists')],
            ]);
        }

        try {
            $alert = StockAlert::create([
                'customer_id' => $customer->id,
                'book_variant_id' => $variant->id,
                'created_at' => now(),
                'notified_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) { // a double tap: the other request made it first
            throw ValidationException::withMessages(['book_variant_id' => [__('messages.stock_alert_exists')]]);
        }

        $alert->load(['bookVariant.book']);

        return (new StockAlertResource($alert))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $alert = StockAlert::where('customer_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $alert->delete();

        return response()->json(null, 204);
    }
}
