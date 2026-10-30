<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Services\Cart\CartService;
use App\Services\Orders\OrderStatusService;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

class ResetDemoCustomer extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Empty the shared demo customer\'s cart and cancel their pending orders older than a day (stock is restored)';

    public function handle(CartService $carts, OrderStatusService $statuses): int
    {
        $demo = Customer::where('email', DemoSeeder::EMAIL)->first();
        if (! $demo) {
            $this->info('No demo account ('.DemoSeeder::EMAIL.'): nothing to reset.');

            return self::SUCCESS;
        }

        $carts->clear($demo);

        // The real customer cancel, so stock and coupon uses come back exactly as they would for a shopper.
        $stale = $demo->orders()->where('status', OrderStatus::Pending)->where('placed_at', '<', now()->subDay())->get();
        foreach ($stale as $order) {
            $statuses->cancelByCustomer($order, 'Daily demo reset');
        }

        $this->info("Demo reset: cart emptied, {$stale->count()} stale order(s) cancelled.");

        return self::SUCCESS;
    }
}
