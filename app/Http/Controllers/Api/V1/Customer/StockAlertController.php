<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockAlertResource;
use App\Models\BookVariant;
use App\Models\StockAlert;
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
                'book_variant_id' => ['The selected book format does not exist.'],
            ]);
        }

        if ($variant->format->isDigital()) {
            throw ValidationException::withMessages([
                'book_variant_id' => ['Stock alerts are only available for physical book formats.'],
            ]);
        }

        if (! $variant->is_active) {
            throw ValidationException::withMessages([
                'book_variant_id' => ['This book format is not active.'],
            ]);
        }

        if (! $variant->book || ! $variant->book->isPublic()) {
            throw ValidationException::withMessages([
                'book_variant_id' => ['This book is not currently available.'],
            ]);
        }

        if ($variant->stock_quantity > 0) {
            throw ValidationException::withMessages([
                'book_variant_id' => ['This book format is already in stock.'],
            ]);
        }

        $customer = $request->user();

        $openCount = StockAlert::where('customer_id', $customer->id)
            ->whereNull('notified_at')
            ->count();

        if ($openCount >= 50) {
            throw ValidationException::withMessages([
                'book_variant_id' => ['You can have at most 50 active stock alerts.'],
            ]);
        }

        $existing = StockAlert::where('customer_id', $customer->id)
            ->where('book_variant_id', $variant->id)
            ->whereNull('notified_at')
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'book_variant_id' => ['You already have an active stock alert for this format.'],
            ]);
        }

        $alert = StockAlert::create([
            'customer_id' => $customer->id,
            'book_variant_id' => $variant->id,
            'created_at' => now(),
            'notified_at' => null,
        ]);

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
