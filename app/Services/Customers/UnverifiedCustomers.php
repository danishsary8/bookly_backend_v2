<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\VerificationToken;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sign-ups that were never finished (the email code was never entered, or the phone never confirmed in Telegram).
 *
 * They can't shop, so they only hold an email address hostage. After `auth.unverified_customer_hours`
 * (48 by default) they are deleted for good. Deactivated accounts are kept: staff turned them off on
 * purpose, and deleting them would let the same email sign up again.
 */
class UnverifiedCustomers
{
    public static function hours(): int
    {
        return max(1, (int) config('auth.unverified_customer_hours', 48));
    }

    /** When this account will be removed if it stays unverified; null for accounts that are kept. */
    public static function removalAt(Customer $customer): ?CarbonInterface
    {
        if ($customer->isVerified() || ! $customer->is_active || $customer->created_at === null) {
            return null;
        }

        return $customer->created_at->copy()->addHours(self::hours());
    }

    /** Accounts past their time: never verified, still active, nothing attached to them. */
    public function expired(): Builder
    {
        return Customer::query()
            ->unverified()
            ->where('is_active', true)
            ->where('created_at', '<', now()->subHours(self::hours()))
            ->whereDoesntHave('orders')
            ->whereDoesntHave('returns')
            ->whereDoesntHave('reviews')
            ->whereDoesntHave('addresses');
    }

    /** Deletes expired sign-ups with their sessions and codes. Returns how many were removed. */
    public function prune(): int
    {
        $removed = 0;
        $this->expired()->select('id')->chunkById(200, function ($customers) use (&$removed) {
            $this->purge($customers->pluck('id')->all());
            $removed += $customers->count();
        });

        return $removed;
    }

    /** Can staff delete this account now? Only unfinished sign-ups with nothing attached. */
    public function deletable(Customer $customer): bool
    {
        return ! $customer->isVerified()
            && ! $customer->orders()->exists() && ! $customer->returns()->exists()
            && ! $customer->reviews()->exists() && ! $customer->addresses()->exists();
    }

    /** Deletes one unfinished sign-up right away (an admin freeing up its email). */
    public function delete(Customer $customer): void
    {
        $this->purge([$customer->id]);
    }

    /** @param  list<int>  $ids */
    private function purge(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            PersonalAccessToken::where('tokenable_type', (new Customer)->getMorphClass())->whereIn('tokenable_id', $ids)->delete();
            VerificationToken::where('user_type', 'customer')->whereIn('user_id', $ids)->delete();
            Customer::withTrashed()->whereIn('id', $ids)->forceDelete(); // cart and wishlist rows cascade
        });
    }

    /**
     * The free host has no scheduler, so busy endpoints call this: it prunes at most once an hour.
     */
    public function pruneIfDue(): void
    {
        if (Cache::add('customers:unverified-pruned', true, now()->addHour())) {
            $this->prune();
        }
    }
}
