<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\KhqrPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The KHQR payment page (KHQR payments design 3.3): the customer's own KHQR order, its QR codes and time left.
 * The page asks every 3 seconds; each ask also checks Bakong (in bakong mode) and the 15-minute limit.
 */
class OrderPaymentController extends Controller
{
    public function __construct(private readonly KhqrPaymentService $payments) {}

    public function show(Request $request, int $order): JsonResponse
    {
        return $this->answer($this->payments->refresh($this->find($request, $order)));
    }

    public function qr(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['currency' => ['required', Rule::in(['USD', 'KHR'])]]);
        $found = $this->find($request, $order);
        $this->payments->qr($found, $data['currency']);

        return $this->answer($found->fresh());
    }

    public function simulate(Request $request, int $order): JsonResponse
    {
        return $this->answer($this->payments->simulate($this->find($request, $order)));
    }

    private function find(Request $request, int $order): Order
    {
        return $request->user()->orders()->where('payment_method', PaymentMethod::Khqr)->findOrFail($order);
    }

    private function answer(Order $order): JsonResponse
    {
        $rows = $order->payments()->where('provider', PaymentProvider::Bakong)->orderBy('id')->get();
        $paid = $rows->firstWhere('status', PaymentStatus::Succeeded);
        $deadline = $this->payments->deadline($order);
        $status = match (true) {
            $order->status === OrderStatus::Pending => 'awaiting_payment',
            $order->status === OrderStatus::Cancelled && $paid === null && $order->statusHistory()->where('note', 'like', '%KHQR payment time ended%')->exists() => 'expired',
            $order->status === OrderStatus::Cancelled => 'cancelled',
            default => 'paid',
        };

        return response()->json(['data' => [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $status,
            'mode' => KhqrPaymentService::mode(),
            'expires_at' => $deadline->toIso8601String(),
            'seconds_left' => $status === 'awaiting_payment' ? max(0, (int) now()->diffInSeconds($deadline, false)) : 0,
            'total_usd' => (string) $order->total_amount,
            'paid' => $paid ? ['currency' => $paid->currency, 'amount' => (string) $paid->amount, 'paid_at' => $paid->raw_response['paid_at'] ?? null] : null,
            // Only the waiting QRs, one per currency (newest).
            'qrs' => $status === 'awaiting_payment'
                ? $rows->where('status', PaymentStatus::Pending)->sortByDesc('id')->unique('currency')->sortBy('currency', SORT_STRING, true)->values()->map(fn ($p) => [
                    'currency' => $p->currency,
                    'amount' => $p->currency === 'KHR' ? (string) (int) $p->amount : (string) $p->amount,
                    'qr' => $p->raw_response['qr'] ?? null,
                    'md5' => $p->provider_transaction_id,
                ])->all()
                : [],
        ]]);
    }
}
