<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderRatingResource;
use App\Models\Order;
use App\Models\OrderRating;
use App\Models\StaffUser;
use App\Notifications\LowOrderRatingNotification;
use App\Services\Telegram\OpsAlerts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class OrderRatingController extends Controller
{
    public function show(Request $request, int $order)
    {
        Order::where('customer_id', $request->user()->id)->findOrFail($order);
        $rating = OrderRating::with('order')->where('order_id', $order)->first();

        return response()->json(['data' => $rating ? new OrderRatingResource($rating) : null]);
    }

    public function store(Request $request, int $order)
    {
        return $this->save($request, $order, false);
    }

    public function update(Request $request, int $order)
    {
        return $this->save($request, $order, true);
    }

    private function save(Request $request, int $order, bool $editing)
    {
        $input = $request->validate(['stars' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:500', 'not_regex:/[<>]/']]);
        [$rating, $alert] = DB::transaction(function () use ($request, $order, $input, $editing) {
            $owned = Order::where('customer_id', $request->user()->id)->lockForUpdate()->findOrFail($order);
            if ($owned->status !== OrderStatus::Delivered) {
                throw ValidationException::withMessages(['stars' => __('messages.rating_delivered')]);
            }
            $rating = OrderRating::where('order_id', $owned->id)->first();
            if ($editing) {
                abort_unless($rating, 404);
                if (now()->gte($rating->created_at->copy()->addDays(7))) {
                    throw ValidationException::withMessages(['stars' => __('messages.rating_window')]);
                }
            } elseif ($rating) {
                throw ValidationException::withMessages(['stars' => __('messages.rating_once')]);
            }
            $before = $rating?->stars ?? 5;
            $rating ??= new OrderRating;
            $rating->fill($input);
            $rating->order_id = $owned->id;
            $rating->customer_id = $request->user()->id;
            $rating->save();

            return [$rating->load('order'), $rating->stars <= 2 && $before > 2];
        });
        if ($alert) {
            Notification::send(StaffUser::where('is_active', true)->get(), new LowOrderRatingNotification($order, $rating->order->order_number, $rating->stars));
            app(OpsAlerts::class)->lowOrderRating($rating->order, $rating->stars);
        }

        return (new OrderRatingResource($rating))->response()->setStatusCode($editing ? 200 : 201);
    }

    public function storeRating()
    {
        $summary = Cache::remember('store-rating:v1', 3600, function () {
            $result = OrderRating::query()->selectRaw('COUNT(*) AS count, AVG(stars) AS average')->first();

            return ['count' => (int) $result->count, 'average' => $result->average === null ? null : round((float) $result->average, 1)];
        });

        return response()->json(['data' => $summary['count'] >= 10 ? $summary : null]);
    }
}
