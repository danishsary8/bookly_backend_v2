<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Services\Payments\KhqrPaymentService;
use Illuminate\Console\Command;

/** KHQR orders whose time to pay is over: one last check (Bakong mode), then cancelled with stock back. */
class ExpireKhqrPayments extends Command
{
    protected $signature = 'payments:expire-khqr';

    protected $description = 'Cancel KHQR orders that were not paid in time (stock and coupon use given back)';

    public function handle(KhqrPaymentService $payments): int
    {
        $cutoff = now()->subMinutes((int) config('payments.khqr.pay_minutes'));
        $done = 0;
        Order::where('payment_method', PaymentMethod::Khqr)->where('status', OrderStatus::Pending)->where('placed_at', '<=', $cutoff)
            ->orderBy('id')->limit(200)->get()
            ->each(function (Order $order) use ($payments, &$done) {
                $done += $payments->refresh($order)->status === OrderStatus::Cancelled ? 1 : 0;
            });
        $this->info("Cancelled {$done} unpaid KHQR order(s).");

        return self::SUCCESS;
    }
}
