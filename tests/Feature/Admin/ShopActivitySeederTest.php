<?php

namespace Tests\Feature\Admin;

use App\Models\BookVariant;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ShopActivitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ShopActivitySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fills_six_months_of_costed_restocks_and_orders_once(): void
    {
        Http::fake();
        Mail::fake();
        $this->seed(DemoSeeder::class);
        $this->seed(ShopActivitySeeder::class);

        $this->assertSame(5, Supplier::where('name', 'not like', '%(direct)')->count());
        $this->assertTrue(Supplier::where('type', 'publisher')->whereNotNull('publisher_id')->exists());
        $this->assertTrue(Supplier::where('type', 'distributor')->exists());
        $this->assertGreaterThan(100, Order::where('status', 'delivered')->count());
        $this->assertTrue(Order::where('created_at', '<', now()->subDays(150))->exists());
        $this->assertTrue(InventoryMovement::whereNotNull('unit_cost_usd')->where('created_at', '>', now()->subDays(30))->exists());
        $this->assertSame(0, BookVariant::whereIn('format', ['paperback', 'hardcover'])->where('is_active', true)->whereNull('cost_price_usd')->count());
        $this->assertSame(0, BookVariant::where('stock_quantity', '<', 0)->count());

        foreach (['requested', 'approved', 'refunded', 'rejected'] as $state) {
            $this->assertTrue(OrderReturn::where('status', $state)->exists(), $state);
        }

        $orders = Order::count();
        $this->seed(ShopActivitySeeder::class);
        $this->assertSame($orders, Order::count());
    }
}
