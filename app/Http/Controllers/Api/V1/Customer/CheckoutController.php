<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Services\CurrencyService;
use App\Services\Orders\CheckoutService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $checkout) {}

    /** Order totals for the current cart (shipping fee, discount), nothing is saved. */
    public function preview(Request $request, CurrencyService $currency): JsonResponse
    {
        $data = $request->validate(['coupon_code' => ['nullable', 'string', 'max:50']]);
        $totals = $this->checkout->quote($request->user(), $data['coupon_code'] ?? null);

        $money = fn (int $cents) => ['usd' => Money::format($cents), 'khr' => $currency->usdToKhr(Money::format($cents))];

        return response()->json(['data' => [
            'subtotal' => $money($totals['subtotal_cents']),
            'discount' => $money($totals['discount_cents']),
            'shipping_fee' => $money($totals['shipping_cents']),
            'tax' => $money($totals['tax_cents']),
            'total' => $money($totals['total_cents']),
            'coupon_code' => $totals['coupon']?->code,
            'requires_shipping' => $totals['requires_shipping'],
            'can_checkout' => $totals['can_checkout'],
        ]]);
    }

    /**
     * Places a cash-on-delivery order. The `Idempotency-Key` header is required: repeating a request
     * with the same key returns the order that was already placed instead of creating a second one.
     */
    public function store(Request $request): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key');
        if (! preg_match('/^[A-Za-z0-9_-]{8,100}$/', $key)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Send a unique Idempotency-Key header (8-100 letters, numbers, - or _) with each checkout.',
            ]);
        }

        $data = $request->validate([
            'address_id' => ['required', 'integer'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'coupon_code' => ['nullable', 'string', 'max:50'],
        ]);

        if ($data['payment_method'] !== PaymentMethod::Cod->value) {
            throw ValidationException::withMessages(['payment_method' => 'Only cash on delivery is available right now.']);
        }

        ['order' => $order, 'created' => $created] = $this->checkout->place(
            $request->user(), $data['address_id'], $data['coupon_code'] ?? null, $key,
        );

        $order->load(['items.variant.book' => fn ($q) => $q->withTrashed(), 'payments', 'coupon', 'statusHistory']);

        return (new OrderResource($order))
            ->response()
            ->setStatusCode($created ? 201 : 200)
            ->header('Idempotent-Replayed', $created ? 'false' : 'true');
    }
}
