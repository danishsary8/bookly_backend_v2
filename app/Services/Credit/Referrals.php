<?php

namespace App\Services\Credit;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Support\Money;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;

class Referrals
{
    public function profile(Customer $customer, ?string $device): object
    {
        DB::table('referral_profiles')->insertOrIgnore([
            'customer_id' => $customer->id, 'code' => strtoupper(substr(hash_hmac('sha256', 'bookly-referral-code-v1|'.$customer->id, config('app.key')), 0, 16)),
            'device_hash' => $this->deviceHash($device), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('referral_profiles')->where('customer_id', $customer->id)->first();
    }

    public function attribute(Customer $friend, ?string $code, ?string $device): void
    {
        if (! config('shop.referrals_enabled')) {
            return;
        }
        $own = $this->profile($friend, $device);
        $sharerProfile = is_string($code) ? DB::table('referral_profiles')->where('code', strtoupper($code))->first() : null;
        $sharer = $sharerProfile ? Customer::find($sharerProfile->customer_id) : null;
        if (! $sharer || ! $sharer->email_verified_at || ! $sharer->is_active || $sharer->email === DemoSeeder::EMAIL
            || $sharer->id === $friend->id || $this->sameIdentity($friend, $sharer)
            || ($own->device_hash !== null && $own->device_hash === $sharerProfile->device_hash)) {
            return;
        }
        DB::table('referrals')->insertOrIgnore(['sharer_customer_id' => $sharer->id, 'friend_customer_id' => $friend->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Apply after the ordinary offer/coupon; minimum is the remaining merchandise amount, excluding delivery. */
    public function discount(Customer $friend, int $netGoodsCents, ?CustomerAddress $address, bool $lock = false): int
    {
        if (! config('shop.referrals_enabled') || ! $friend->email_verified_at || $friend->email === DemoSeeder::EMAIL || $netGoodsCents < 1000) {
            return 0;
        }
        $query = DB::table('referrals')->where('friend_customer_id', $friend->id)->where('status', 'pending');
        $referral = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $referral || Order::withTrashed()->where('customer_id', $friend->id)->exists()) {
            return 0;
        }
        $sharer = Customer::find($referral->sharer_customer_id);
        if (! $sharer || ! $sharer->is_active || $this->sameIdentity($friend, $sharer) || $this->sameDevice($friend, $sharer) || $this->sameAddress($sharer, $address)) {
            return 0;
        }

        return min($netGoodsCents, max(0, Money::toCents((string) config('shop.referral_friend_discount'))));
    }

    public function placed(Order $order): void
    {
        if (! config('shop.referrals_enabled')) {
            return;
        }
        DB::table('referrals')->where('friend_customer_id', $order->customer_id)->where('status', 'pending')->update([
            'order_id' => $order->id, 'discount_cents' => $order->referral_discount_cents,
            'status' => $order->referral_discount_cents > 0 ? 'qualified' : 'void',
            'voided_at' => $order->referral_discount_cents > 0 ? null : now(), 'updated_at' => now(),
        ]);
    }

    public function reward(int $id): void
    {
        if (! config('shop.referrals_enabled')) {
            return;
        }
        DB::transaction(function () use ($id) {
            $referral = DB::table('referrals')->where('id', $id)->first();
            if (! $referral || $referral->status !== 'qualified') {
                return;
            }
            $order = Order::withTrashed()->whereKey($referral->order_id)->lockForUpdate()->first();
            $referral = DB::table('referrals')->where('id', $id)->lockForUpdate()->first();
            if ($referral->status !== 'qualified') {
                return;
            }
            if (! $order || in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Returned], true) || $order->returns()->exists()) {
                $this->void($id);

                return;
            }
            $delivered = $order->statusHistory()->where('status', 'delivered')->min('created_at');
            if ($order->status !== OrderStatus::Delivered || ! $delivered || now()->lessThan(CarbonImmutable::parse($delivered)->addDays(7))) {
                return;
            }
            $sharer = Customer::find($referral->sharer_customer_id);
            $friend = Customer::find($referral->friend_customer_id);
            $address = new CustomerAddress(['address_line1' => $order->shipping_address_line1, 'address_line2' => $order->shipping_address_line2, 'city' => $order->shipping_city, 'state' => $order->shipping_state, 'country' => $order->shipping_country]);
            if (! $sharer || ! $friend || ! $sharer->is_active || ! $friend->email_verified_at || $this->sameIdentity($friend, $sharer) || $this->sameDevice($friend, $sharer) || $this->sameAddress($sharer, $address)) {
                $this->void($id);

                return;
            }
            // One profile lock serializes the monthly cap across different friends.
            DB::table('referral_profiles')->where('customer_id', $sharer->id)->lockForUpdate()->first();
            $monthStart = now(config('shop.timezone'))->startOfMonth()->utc();
            if (DB::table('referrals')->where('sharer_customer_id', $sharer->id)->where('rewarded_at', '>=', $monthStart)->count() >= 10) {
                $this->void($id);

                return;
            }
            $reward = max(0, Money::toCents((string) config('shop.referral_sharer_credit')));
            if ($reward > 0) {
                app(CreditWallet::class)->move($sharer, $reward, 'referral:'.$id);
            }
            DB::table('referrals')->where('id', $id)->update(['status' => 'rewarded', 'reward_cents' => $reward, 'rewarded_at' => now(), 'updated_at' => now()]);
        });
    }

    public function voidForOrder(Order $order): void
    {
        $id = DB::table('referrals')->where('order_id', $order->id)->value('id');
        if ($id) {
            $this->void($id);
        }
    }

    /** Voiding is allowed even after a flag is switched off, so existing liabilities remain correct. */
    public function void(int $id): void
    {
        DB::transaction(function () use ($id) {
            $referral = DB::table('referrals')->where('id', $id)->lockForUpdate()->first();
            if (! $referral || $referral->status === 'void') {
                return;
            }
            $unrecovered = 0;
            if ($referral->status === 'rewarded' && $referral->reward_cents > 0) {
                $sharer = Customer::withTrashed()->findOrFail($referral->sharer_customer_id);
                $wallet = app(CreditWallet::class);
                $recover = min($wallet->balance($sharer, lock: true), $referral->reward_cents);
                if ($recover > 0) {
                    $wallet->move($sharer, -$recover, 'referral-void:'.$id);
                }
                $unrecovered = $referral->reward_cents - $recover;
            }
            DB::table('referrals')->where('id', $id)->update(['status' => 'void', 'voided_at' => now(), 'unrecovered_cents' => $unrecovered, 'updated_at' => now()]);
        });
    }

    private function deviceHash(?string $device): ?string
    {
        return is_string($device) && preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $device) ? hash_hmac('sha256', 'bookly-referral-device|'.$device, config('app.key')) : null;
    }

    private function sameDevice(Customer $a, Customer $b): bool
    {
        $hashes = DB::table('referral_profiles')->whereIn('customer_id', [$a->id, $b->id])->pluck('device_hash', 'customer_id');

        return isset($hashes[$a->id], $hashes[$b->id]) && hash_equals($hashes[$a->id], $hashes[$b->id]);
    }

    private function sameIdentity(Customer $a, Customer $b): bool
    {
        $phone = fn ($c) => PhoneNumber::normalize($c->phone_e164 ?? $c->phone) ?? preg_replace('/\D/', '', $c->phone_e164 ?? $c->phone ?? '');

        return ($a->email && $b->email && mb_strtolower($a->email) === mb_strtolower($b->email)) || ($phone($a) !== '' && $phone($a) === $phone($b));
    }

    private function sameAddress(Customer $sharer, ?CustomerAddress $address): bool
    {
        if (! $address || ! $address->address_line1) {
            return false;
        }
        $normalize = fn ($a) => preg_replace('/[\s\p{P}]+/u', '', mb_strtolower(implode('|', [$a->address_line1, $a->address_line2, $a->city, $a->state, $a->country])));
        $value = $normalize($address);

        return $sharer->addresses()->get()->contains(fn ($candidate) => $normalize($candidate) === $value);
    }
}
