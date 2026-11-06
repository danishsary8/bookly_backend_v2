<?php

namespace App\Services\Credit;

use App\Models\Customer;
use Database\Seeders\DemoSeeder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GiftRedemption
{
    public function __construct(private readonly GiftCode $codes, private readonly CreditWallet $wallet) {}

    /** Request-level customer and IP throttles must also be enforced by the eventual API route. */
    public function redeem(Customer $customer, string $code): int
    {
        if (! config('shop.gift_cards_enabled')) {
            throw new NotFoundHttpException;
        }
        if (! $customer->is_active || ! $customer->isVerified() || $customer->email === DemoSeeder::EMAIL) {
            throw new DomainException('Gift card cannot be redeemed.');
        }

        $code = strtoupper(trim($code));
        $valid = (bool) preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{16}$/', $code);
        $fingerprint = $this->codes->fingerprint($valid ? $code : str_repeat('A', 16));

        $result = DB::transaction(function () use ($customer, $fingerprint, $valid) {
            DB::table('gift_redemption_attempts')->insertOrIgnore(['customer_id' => $customer->id, 'created_at' => now(), 'updated_at' => now()]);
            $attempt = DB::table('gift_redemption_attempts')->where('customer_id', $customer->id)->lockForUpdate()->first();
            if ($attempt->locked_until !== null && now()->lessThan($attempt->locked_until)) {
                return null;
            }
            $failures = $attempt->locked_until !== null ? 0 : (int) $attempt->failures;
            $card = DB::table('gift_cards')->where('code_hash', $fingerprint)->lockForUpdate()->first();
            $stored = $card?->code_hash ?? str_repeat('0', 64);
            if (! hash_equals($stored, $fingerprint) || ! $valid || ! $card || $card->status !== 'active'
                || $card->paid_at === null || $card->expires_at === null || now()->greaterThanOrEqualTo($card->expires_at)) {
                $failures++;
                DB::table('gift_redemption_attempts')->where('customer_id', $customer->id)->update([
                    'failures' => $failures, 'locked_until' => $failures >= 10 ? now()->addHour() : null, 'updated_at' => now(),
                ]);

                return null;
            }

            $balance = $this->wallet->move($customer, (int) $card->amount_cents, 'gift-card:'.$card->id);
            DB::table('gift_cards')->where('id', $card->id)->update([
                'status' => 'redeemed', 'redeemed_by_customer_id' => $customer->id,
                'redeemed_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('gift_redemption_attempts')->where('customer_id', $customer->id)->update(['failures' => 0, 'locked_until' => null, 'updated_at' => now()]);

            return $balance;
        });

        if ($result === null) {
            throw new DomainException('Gift card cannot be redeemed.');
        }

        return $result;
    }
}
