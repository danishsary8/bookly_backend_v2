<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Services\Cart\CartService;
use App\Services\Orders\OrderStatusService;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Throwable;

class ResetDemoCustomer extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Restore the shared demo customer: name and password, empty cart, pending orders older than an hour cancelled (stock restored)';

    public function handle(CartService $carts, OrderStatusService $statuses): int
    {
        $demo = Customer::where('email', DemoSeeder::EMAIL)->first();
        if (! $demo) {
            $this->info('No demo account ('.DemoSeeder::EMAIL.'): nothing to reset.');

            return self::SUCCESS;
        }

        // Visitors can rename the account; sign-in details are guarded by EnsureNotDemoAccount, the password reset is a backstop.
        // Telegram: a visitor could link their own chat to the shared account and then get every other visitor's
        // order updates, so the link is removed (the route is blocked for the demo too; this clears older links).
        $demo->forceFill([
            'name' => 'Demo Customer', 'password_hash' => DemoSeeder::PASSWORD,
            'telegram_chat_id' => null, 'telegram_order_updates' => false, 'price_drop_alerts' => false,
        ])->save();
        $carts->clear($demo);
        $removedAddresses = $this->resetAddresses($demo);

        // The same cancel rules as a shopper (stock and coupon uses come back), recorded as automatic in the history.
        // One order that can't be cancelled (e.g. staff moved it on a moment earlier) is reported and skipped, so it
        // never holds back the others; it is tried again on the next run if it is still pending.
        $stale = $demo->orders()->where('status', OrderStatus::Pending)->where('placed_at', '<', now()->subHour())->get();
        $cancelled = 0;
        foreach ($stale as $order) {
            try {
                $statuses->cancelAutomatically($order, 'hourly demo reset');
                $cancelled++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->info("Demo reset: cart emptied, {$removedAddresses} visitor address(es) removed, {$cancelled} stale order(s) cancelled.");

        return self::SUCCESS;
    }

    /**
     * Visitors may type a real home address into the shared account, and the next visitor would see it. Keep only the
     * demo's own sample address (made again if a visitor deleted it) as the default. Orders keep their own copy of the
     * address, so their history is unchanged.
     */
    private function resetAddresses(Customer $demo): int
    {
        $sampleId = $demo->addresses()->where('address_line1', DemoSeeder::ADDRESS_LINE1)->oldest('id')->value('id');

        $removed = 0;
        foreach ($demo->addresses()->when($sampleId !== null, fn ($q) => $q->whereKeyNot($sampleId))->get() as $address) {
            $address->delete();
            $removed++;
        }

        if ($sampleId === null) {
            $demo->addresses()->create([
                'label' => 'Home', 'recipient_name' => 'Demo Customer', 'phone' => $demo->phone,
                'address_line1' => DemoSeeder::ADDRESS_LINE1, 'city' => 'Phnom Penh', 'country' => 'Cambodia', 'is_default' => true,
            ]);
        } else {
            $demo->addresses()->whereKey($sampleId)->where('is_default', false)->update(['is_default' => true]);
        }

        return $removed;
    }
}
