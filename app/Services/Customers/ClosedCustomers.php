<?php

namespace App\Services\Customers;

use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Models\Customer;
use App\Models\VerificationToken;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Customers closing their own account (Account → Sign-in & security → Delete my account).
 *
 * Closing signs them out everywhere, removes their reviews, wishlist and cart, and hides the account
 * (soft delete): nobody can sign in to it and its email or phone can't be used for a new account yet.
 * After `auth.closed_account_days` (30) the personal details are erased: name, email, phone, sign-in
 * ids and saved addresses. Orders and returns stay, with the delivery details printed on them, because
 * the shop must keep its sales records.
 */
class ClosedCustomers
{
    public const ERASED_NAME = 'Deleted customer';

    private const OPEN_ORDERS = [OrderStatus::Pending, OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Shipped];

    private const OPEN_RETURNS = [ReturnStatus::Requested, ReturnStatus::Approved];

    public static function days(): int
    {
        return max(1, (int) config('auth.closed_account_days', 30));
    }

    /** Why this account can't be closed yet, or null when it can. */
    public function blocker(Customer $customer): ?string
    {
        if ($customer->orders()->whereIn('status', self::OPEN_ORDERS)->exists()) {
            return 'You have an order on its way. Cancel it, or wait until it is delivered, then close your account.';
        }
        if ($customer->returns()->whereIn('status', self::OPEN_RETURNS)->exists()) {
            return 'You have a return in progress. Close your account once it is finished.';
        }

        return null;
    }

    /** @return CarbonInterface when the personal details will be erased */
    public function close(Customer $customer): CarbonInterface
    {
        DB::transaction(function () use ($customer) {
            $customer->tokens()->delete();
            $customer->reviews()->delete();
            $customer->wishlistBooks()->detach();
            $customer->cart()->delete();
            $customer->delete();
        });

        return $customer->deleted_at->copy()->addDays(self::days());
    }

    /** When a closed account's details are erased (null for open accounts). */
    public static function eraseAt(Customer $customer): ?CarbonInterface
    {
        return $customer->deleted_at?->copy()->addDays(self::days());
    }

    /** Closed accounts still inside their 30 days: the only ones staff see in "Closed" and can reopen. */
    public function reopenable(): Builder
    {
        return Customer::onlyTrashed()->where('deleted_at', '>', now()->subDays(self::days()));
    }

    public function canReopen(Customer $customer): bool
    {
        return $customer->trashed() && $customer->deleted_at->gt(now()->subDays(self::days()));
    }

    /**
     * Opens a closed account again (an admin, at the customer's request). The email, phone and sign-in ids
     * stayed reserved while it was closed, so nothing can clash. Reviews, wishlist and cart removed at
     * closing stay removed; the customer signs in again as before.
     */
    public function reopen(Customer $customer): void
    {
        $customer->restore();
    }

    /** Erases closed accounts past their 30 days. Returns how many. */
    public function erase(): int
    {
        $erased = 0;
        Customer::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays(self::days()))
            ->where(fn ($q) => $q->where('name', '!=', self::ERASED_NAME)->orWhereNotNull('email')->orWhereNotNull('phone'))
            ->chunkById(100, function ($customers) use (&$erased) {
                foreach ($customers as $customer) {
                    $this->eraseOne($customer);
                    $erased++;
                }
            });

        return $erased;
    }

    private function eraseOne(Customer $customer): void
    {
        DB::transaction(function () use ($customer) {
            $customer->addresses()->delete();
            VerificationToken::where('user_type', 'customer')->where('user_id', $customer->id)->delete();
            $customer->forceFill([
                'name' => self::ERASED_NAME,
                'email' => null,
                'phone' => null,
                'phone_e164' => null,
                'password_hash' => null,
                'google_id' => null,
                'facebook_id' => null,
                'email_verified_at' => null,
                'phone_verified_at' => null,
            ])->save();
        });
    }

    /** The free host has no scheduler, so busy endpoints call this: at most once an hour. */
    public function eraseIfDue(): void
    {
        if (Cache::add('customers:closed-erased', true, now()->addHour())) {
            $this->erase();
        }
    }
}
