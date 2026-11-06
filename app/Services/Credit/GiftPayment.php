<?php

namespace App\Services\Credit;

use App\Models\Customer;
use App\Services\Payments\Khqr\BakongClient;
use App\Services\Payments\Khqr\BakongUnavailable;
use App\Services\Payments\Khqr\KhqrTransactionMatcher;
use App\Services\Payments\KhqrPaymentService;
use App\Services\Telegram\OpsAlerts;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GiftPayment
{
    public function __construct(private readonly BakongClient $bakong, private readonly OpsAlerts $alerts) {}

    /** Only an owner can trigger a provider check; supplied client data never proves payment. */
    public function refresh(Customer $buyer, int $id): string
    {
        if (! config('shop.gift_cards_enabled')) {
            throw new NotFoundHttpException;
        }
        $card = DB::table('gift_cards')->where('id', $id)->where('buyer_customer_id', $buyer->id)->first();
        if (! $card) {
            throw new NotFoundHttpException;
        }
        $payment = DB::table('gift_card_payments')->where('gift_card_id', $id)->first();
        if (! $payment || ! in_array($payment->status, ['pending', 'expired'], true) || KhqrPaymentService::mode() !== 'bakong') {
            return $card->status;
        }
        try {
            $transaction = $this->bakong->checkByMd5($payment->provider_transaction_id);
        } catch (BakongUnavailable) {
            return $card->status;
        }
        if ($transaction !== null && KhqrTransactionMatcher::matches(Money::format((int) $card->amount_cents), 'USD', $transaction)) {
            return $this->applyVerifiedPayment($id);
        }
        if ($transaction === null && now()->greaterThanOrEqualTo($payment->expires_at)) {
            DB::transaction(function () use ($id) {
                $locked = DB::table('gift_cards')->where('id', $id)->lockForUpdate()->first();
                $payment = DB::table('gift_card_payments')->where('gift_card_id', $id)->lockForUpdate()->first();
                if ($locked->status === 'pending_payment' && $payment->status === 'pending') {
                    DB::table('gift_cards')->where('id', $id)->update(['status' => 'expired', 'updated_at' => now()]);
                    DB::table('gift_card_payments')->where('id', $payment->id)->update(['status' => 'expired', 'updated_at' => now()]);
                }
            });
        }

        return (string) DB::table('gift_cards')->where('id', $id)->value('status');
    }

    /** Only provider-verified transactions reach this method. */
    private function applyVerifiedPayment(int $id): string
    {
        $result = DB::transaction(function () use ($id) {
            $card = DB::table('gift_cards')->where('id', $id)->lockForUpdate()->first();
            $payment = DB::table('gift_card_payments')->where('gift_card_id', $id)->lockForUpdate()->first();
            if (! in_array($payment->status, ['pending', 'expired'], true)) {
                return ['status' => $card->status, 'event' => null];
            }
            $late = $card->status !== 'pending_payment' || now()->greaterThanOrEqualTo($payment->expires_at);
            $status = $late ? ($card->status === 'cancelled' ? 'cancelled' : 'expired') : 'active';
            DB::table('gift_card_payments')->where('id', $payment->id)->update(['status' => $late ? 'late' : 'succeeded', 'paid_at' => now(), 'updated_at' => now()]);
            DB::table('gift_cards')->where('id', $id)->update([
                'status' => $status, 'paid_at' => now(),
                'expires_at' => $late ? null : now()->addYears(2), 'updated_at' => now(),
            ]);

            return ['status' => $status, 'event' => $late ? 'late' : 'paid'];
        });
        if ($result['event'] === 'paid') {
            $this->alerts->giftCardPaid($id);
        } elseif ($result['event'] === 'late') {
            $this->alerts->giftCardLatePayment($id);
        }

        return $result['status'];
    }
}
