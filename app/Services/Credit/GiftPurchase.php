<?php

namespace App\Services\Credit;

use App\Models\Customer;
use App\Services\Payments\Khqr\KhqrBuilder;
use App\Services\Payments\KhqrPaymentService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GiftPurchase
{
    public function __construct(private readonly GiftCode $codes) {}

    /** Creates only an unpaid payment request; no credit exists before verified payment. */
    public function start(Customer $buyer, int $amountCents, string $recipientName, string $recipientEmail, ?string $message, string $sendOn, string $idempotencyKey): int
    {
        if (! config('shop.gift_cards_enabled')) {
            throw new NotFoundHttpException;
        }
        if (! config('payments.khqr.enabled') || (app()->environment('production') && KhqrPaymentService::mode() !== 'bakong')) {
            throw new DomainException('Gift card payment is unavailable.');
        }
        $today = CarbonImmutable::now(config('shop.timezone'))->startOfDay();
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $sendOn, config('shop.timezone'));
        } catch (\InvalidArgumentException) {
            throw new DomainException('Invalid gift card details.');
        }
        if (! $date || $date->format('Y-m-d') !== $sendOn || $date->lt($today) || $date->gt($today->addDays(90))
            || $amountCents < 500 || $amountCents > 20000 || trim($recipientName) === '' || mb_strlen($recipientName) > 120
            || ! filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($recipientEmail) > 190
            || ($message !== null && mb_strlen($message) > 300) || ! preg_match('/^[A-Za-z0-9_-]{8,100}$/', $idempotencyKey)) {
            throw new DomainException('Invalid gift card details.');
        }

        return DB::transaction(function () use ($buyer, $amountCents, $recipientName, $recipientEmail, $message, $sendOn, $idempotencyKey) {
            $lockedBuyer = Customer::whereKey($buyer->id)->lockForUpdate()->firstOrFail();
            if (! $lockedBuyer->is_active || ! $lockedBuyer->isVerified() || $lockedBuyer->email === DemoSeeder::EMAIL) {
                throw new DomainException('Gift card purchase is unavailable.');
            }
            $existing = DB::table('gift_cards')->where('buyer_customer_id', $buyer->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->amount_cents !== $amountCents || $existing->recipient_name !== $recipientName
                    || $existing->recipient_email !== $recipientEmail || $existing->message !== $message || $existing->send_on !== $sendOn) {
                    throw new DomainException('Purchase reference already used.');
                }

                return (int) $existing->id;
            }
            $id = DB::table('gift_cards')->insertGetId([
                'buyer_customer_id' => $buyer->id, 'amount_cents' => $amountCents, 'recipient_name' => $recipientName,
                'recipient_email' => $recipientEmail, 'message' => $message, 'send_on' => $sendOn,
                'idempotency_key' => $idempotencyKey, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $code = $this->codes->forId($id);
            DB::table('gift_cards')->where('id', $id)->update(['code_hash' => $this->codes->fingerprint($code), 'last_four' => substr($code, -4)]);
            $created = CarbonImmutable::now();
            $expires = $created->addMinutes((int) config('payments.khqr.pay_minutes'));
            $qr = KhqrBuilder::build(
                accountId: config('payments.khqr.account_id'), merchantName: config('payments.khqr.merchant_name'),
                city: config('payments.khqr.city'), currency: 'USD', amount: Money::format($amountCents),
                createdAtMs: (int) $created->getTimestampMs(), expiresAtMs: (int) $expires->getTimestampMs(),
                billNumber: 'GC-'.$id, storeLabel: substr((string) config('payments.khqr.merchant_name'), 0, 25),
            );
            DB::table('gift_card_payments')->insert([
                'gift_card_id' => $id, 'provider_transaction_id' => KhqrBuilder::md5($qr), 'qr_payload' => $qr,
                'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        });
    }
}
