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

    /** The caller must enforce request throttles and the account's failed-attempt lock. */
    public function redeem(Customer $customer, string $code): int
    {
        if (! config('shop.gift_cards_enabled')) {
            throw new NotFoundHttpException;
        }
        if (! $customer->is_active || ! $customer->isVerified() || $customer->email === DemoSeeder::EMAIL) {
            throw new DomainException('Gift card cannot be redeemed.');
        }

        $code = strtoupper(trim($code));
        if (! preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{16}$/', $code)) {
            throw new DomainException('Gift card cannot be redeemed.');
        }
        $fingerprint = $this->codes->fingerprint($code);

        return DB::transaction(function () use ($customer, $fingerprint) {
            $card = DB::table('gift_cards')->where('code_hash', $fingerprint)->lockForUpdate()->first();
            $stored = $card?->code_hash ?? str_repeat('0', 64);
            if (! hash_equals($stored, $fingerprint) || ! $card || $card->status !== 'active'
                || $card->paid_at === null || $card->expires_at === null || now()->greaterThanOrEqualTo($card->expires_at)) {
                throw new DomainException('Gift card cannot be redeemed.');
            }

            $balance = $this->wallet->move($customer, (int) $card->amount_cents, 'gift-card:'.$card->id);
            DB::table('gift_cards')->where('id', $card->id)->update([
                'status' => 'redeemed', 'redeemed_by_customer_id' => $customer->id,
                'redeemed_at' => now(), 'updated_at' => now(),
            ]);

            return $balance;
        });
    }
}
