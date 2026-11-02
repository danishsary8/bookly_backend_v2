<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\StaffUser;
use App\Notifications\PaidAfterCancelNotification;
use App\Services\CurrencyService;
use App\Services\Orders\CheckoutService;
use App\Services\Orders\OrderStatusService;
use App\Services\Payments\Khqr\BakongClient;
use App\Services\Payments\Khqr\BakongUnavailable;
use App\Services\Payments\Khqr\KhqrBuilder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * KHQR payments (design docs/superpowers/specs/2026-11-02-khqr-payments-design.md). One payment row per QR
 * (USD, and KHR once asked). An order waits `pay_minutes` for payment, then is cancelled automatically and its stock
 * goes back. A payment is counted once: OrderStatusService::markPaid locks the order and allows pending -> paid only.
 */
class KhqrPaymentService
{
    /** Cache key with Bakong's last answer, for the admin health card (no token inside). */
    public const HEALTH_KEY = 'khqr-bakong-last-check';

    public function __construct(
        private readonly OrderStatusService $status,
        private readonly BakongClient $bakong,
        private readonly CurrencyService $currency,
    ) {}

    public static function mode(): string
    {
        return config('payments.khqr.mode');
    }

    public function deadline(Order $order): CarbonImmutable
    {
        return CarbonImmutable::parse($order->placed_at)->addMinutes((int) config('payments.khqr.pay_minutes'));
    }

    /** Called inside checkout's transaction: the first QR, in USD. */
    public function start(Order $order): Payment
    {
        return $this->createQr($order, 'USD');
    }

    /** The waiting QR in this currency, made on first ask (KHR at today's rate, kept on its own row). */
    public function qr(Order $order, string $currency): Payment
    {
        $order = $this->refresh($order);
        if ($order->status !== OrderStatus::Pending) {
            throw ValidationException::withMessages(['order' => 'This order is no longer waiting for payment.']);
        }

        return $this->pendingQrs($order)->firstWhere('currency', $currency) ?? $this->createQr($order, $currency);
    }

    /**
     * Brings a KHQR order up to date: in Bakong mode asks Bakong (at most every 3 seconds per order), and cancels the
     * order once its time is over (after one last check, so a payment in the final seconds is not lost).
     */
    public function refresh(Order $order): Order
    {
        if ($order->payment_method !== PaymentMethod::Khqr || $order->status !== OrderStatus::Pending) {
            return $order;
        }

        $over = now()->greaterThanOrEqualTo($this->deadline($order));
        if (self::mode() === 'bakong' && ($over || Cache::add("khqr-check:{$order->id}", true, now()->addSeconds(3)))) {
            $this->checkWithBakong($order);
            $order = $order->fresh();
        }

        if ($over && $order->status === OrderStatus::Pending) {
            try {
                $order = $this->status->cancelAutomatically($order, 'the KHQR payment time ended.');
            } catch (ValidationException) {
                $order = $order->fresh(); // paid or cancelled at the same moment: keep what happened first
            }
        }

        return $order;
    }

    /** Test mode only: pays the newest waiting QR with no real money. */
    public function simulate(Order $order): Order
    {
        if (self::mode() !== 'test') {
            throw new NotFoundHttpException;
        }
        $order = $this->refresh($order);
        $payment = $order->status === OrderStatus::Pending ? $this->pendingQrs($order)->first() : null;
        if ($payment === null) {
            throw ValidationException::withMessages(['order' => 'This order is no longer waiting for payment.']);
        }

        return $this->markPaid($order, $payment, ['simulated' => true, 'amount' => $payment->amount, 'currency' => $payment->currency], 'KHQR (test, no real money)');
    }

    /** An admin saw the money arrive (e.g. in the bank app) and confirms it. */
    public function markPaidByStaff(Order $order, StaffUser $staff, string $note): Order
    {
        $order = $this->refresh($order);
        $payment = $order->payment_method === PaymentMethod::Khqr && $order->status === OrderStatus::Pending ? $this->pendingQrs($order)->first() : null;
        if ($payment === null) {
            throw ValidationException::withMessages(['order' => 'Only a KHQR order that is waiting for payment can be marked as paid.']);
        }

        return $this->markPaid($order, $payment, ['by_staff_id' => $staff->id, 'amount' => $payment->amount, 'currency' => $payment->currency], "KHQR, confirmed by staff: {$note}", $staff);
    }

    /** Asks Bakong about every waiting QR of the order. Bakong being unavailable never breaks the page. */
    private function checkWithBakong(Order $order): void
    {
        foreach ($this->pendingQrs($order) as $payment) {
            try {
                $transaction = $this->bakong->checkByMd5($payment->provider_transaction_id);
                Cache::put(self::HEALTH_KEY, ['status' => 'ok', 'at' => now()->toIso8601String()], now()->addDay());
            } catch (BakongUnavailable $e) {
                Cache::put(self::HEALTH_KEY, ['status' => $e->reason, 'at' => now()->toIso8601String()], now()->addDay());

                return;
            }
            if ($transaction !== null) {
                $this->markPaid($order, $payment, $transaction, 'KHQR (Bakong)');

                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $transaction  Bakong's answer, or our own record for test / staff payments
     */
    private function markPaid(Order $order, Payment $payment, array $transaction, string $how, ?StaffUser $staff = null): Order
    {
        $fromBakong = ! isset($transaction['simulated']) && ! isset($transaction['by_staff_id']);
        if ($fromBakong) {
            DB::table('payment_webhook_logs')->insertOrIgnore([
                'provider' => 'bakong', 'event_type' => 'check_transaction_by_md5', 'event_id' => $payment->provider_transaction_id,
                'payload' => json_encode($transaction), 'created_at' => now(),
            ]);
            if (! $this->matches($payment, $transaction)) {
                report(new \RuntimeException("KHQR payment {$payment->id}: Bakong amount or currency does not match the QR."));

                return $order; // not counted; the log keeps Bakong's answer for staff
            }
        }

        try {
            $paid = $this->status->markPaid($order, "Paid by {$how}.", $staff, function (Order $locked) use ($payment, $transaction) {
                $row = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                $row->update(['status' => PaymentStatus::Succeeded, 'raw_response' => [...($row->raw_response ?? []), 'paid' => $transaction, 'paid_at' => now()->toIso8601String()]]);
                $locked->payments()->where('status', PaymentStatus::Pending)->whereKeyNot($row->id)->update(['status' => PaymentStatus::Failed]);
            });
        } catch (ValidationException) {
            return $this->paidButNotPending($order, $payment, $transaction);
        }

        if ($fromBakong) {
            PaymentWebhookLog::where('provider', 'bakong')->where('event_id', $payment->provider_transaction_id)->update(['processed_at' => now()]);
        }
        CheckoutService::announce($paid); // "order placed" waits for the payment (design part 1, step 7)

        return $paid;
    }

    /** Already paid (a second check): nothing to do. Cancelled: keep the money on record and tell staff to refund. */
    private function paidButNotPending(Order $order, Payment $payment, array $transaction): Order
    {
        $order = $order->fresh();
        if ($order->status !== OrderStatus::Cancelled) {
            return $order;
        }

        $flagged = DB::transaction(function () use ($order, $payment, $transaction) {
            $row = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($row->status === PaymentStatus::Succeeded) {
                return false;
            }
            $row->update(['status' => PaymentStatus::Succeeded, 'raw_response' => [...($row->raw_response ?? []), 'paid' => $transaction, 'paid_at' => now()->toIso8601String(), 'paid_after_cancel' => true]]);
            $order->statusHistory()->create(['status' => OrderStatus::Cancelled, 'note' => 'KHQR payment received after the order was cancelled: refund the customer by hand.']);

            return true;
        });
        if ($flagged) {
            Notification::send(StaffUser::all(), new PaidAfterCancelNotification([
                'order_id' => $order->id, 'order_number' => $order->order_number, 'amount' => (string) $payment->amount, 'currency' => $payment->currency,
            ]));
        }

        return $order;
    }

    private function matches(Payment $payment, array $transaction): bool
    {
        return strtoupper((string) ($transaction['currency'] ?? '')) === $payment->currency
            && is_numeric($transaction['amount'] ?? null)
            && Money::toCents((string) $transaction['amount']) === Money::toCents((string) $payment->amount);
    }

    /** @return Collection<int, Payment> newest first */
    private function pendingQrs(Order $order)
    {
        return $order->payments()->where('provider', PaymentProvider::Bakong)->where('status', PaymentStatus::Pending)->orderByDesc('id')->get();
    }

    private function createQr(Order $order, string $currency): Payment
    {
        $amount = $currency === 'USD' ? (string) $order->total_amount : $this->currency->usdToKhr((string) $order->total_amount);
        if ($amount === null) {
            throw ValidationException::withMessages(['currency' => 'Paying in riel is not available right now (no exchange rate). Please pay in USD.']);
        }

        $expires = $this->deadline($order);
        $created = now();
        if ($expires->lessThanOrEqualTo($created)) {
            throw ValidationException::withMessages(['order' => 'The time to pay this order is over.']);
        }

        $qr = KhqrBuilder::build(
            accountId: config('payments.khqr.account_id'),
            merchantName: config('payments.khqr.merchant_name'),
            city: config('payments.khqr.city'),
            currency: $currency,
            amount: $currency === 'USD' ? Money::format(Money::toCents($amount)) : (string) (int) $amount,
            createdAtMs: (int) $created->getTimestampMs(),
            expiresAtMs: (int) $expires->getTimestampMs(),
            billNumber: substr((string) $order->order_number, 0, 25),
            storeLabel: substr((string) config('payments.khqr.merchant_name'), 0, 25),
        );
        $md5 = KhqrBuilder::md5($qr);

        return $order->payments()->create([
            'provider' => PaymentProvider::Bakong,
            'provider_transaction_id' => $md5,
            'amount' => $currency === 'USD' ? $amount : (string) (int) $amount,
            'currency' => $currency,
            'status' => PaymentStatus::Pending,
            'raw_response' => ['qr' => $qr, 'md5' => $md5, 'expires_at' => $expires->toIso8601String(), 'mode' => self::mode()],
        ]);
    }
}
