<?php

namespace Tests\Feature\Platform;

use App\Enums\OrderStatus;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DemoResetCommandTest extends TestCase
{
    use RefreshDatabase;

    private static int $key = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /** Places an order through the real cart and checkout, then moves it back in time. */
    private function placeOrder(Customer $customer, BookVariant $variant, int $qty, int $hoursAgo): Order
    {
        $this->withToken($customer->createToken('t', ['customer'])->plainTextToken);
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => $qty])->assertCreated();
        $address = CustomerAddress::factory()->for($customer)->create();
        $id = $this->withHeader('Idempotency-Key', 'demo-reset-key-'.(++self::$key))->postJson('/api/v1/checkout', [
            'address_id' => $address->id, 'payment_method' => 'cod',
        ])->assertCreated()->json('data.id');

        $order = Order::findOrFail($id);
        $order->forceFill(['placed_at' => now()->subHours($hoursAgo)])->save();

        return $order;
    }

    private function addToCart(Customer $customer, BookVariant $variant): void
    {
        $this->withToken($customer->createToken('t', ['customer'])->plainTextToken);
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => 1])->assertCreated();
    }

    private function demo(): Customer
    {
        return Customer::factory()->create(['email' => 'demo@bookly.test']);
    }

    public function test_it_empties_the_demo_cart_and_cancels_stale_pending_orders_restoring_stock(): void
    {
        $demo = $this->demo();
        $variant = BookVariant::factory()->create(['stock_quantity' => 10]);
        $order = $this->placeOrder($demo, $variant, 2, 25);
        $this->addToCart($demo, BookVariant::factory()->create(['stock_quantity' => 5]));
        $this->assertSame(8, $variant->fresh()->stock_quantity);

        $this->artisan('demo:reset')->assertExitCode(0);

        $this->assertSame(0, $demo->cart()->first()?->items()->count() ?? 0);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(10, $variant->fresh()->stock_quantity);
    }

    public function test_it_keeps_recent_pending_and_non_pending_orders(): void
    {
        $demo = $this->demo();
        $recent = $this->placeOrder($demo, BookVariant::factory()->create(['stock_quantity' => 10]), 1, 2);
        $delivered = $this->placeOrder($demo, BookVariant::factory()->create(['stock_quantity' => 10]), 1, 72);
        $delivered->forceFill(['status' => OrderStatus::Delivered])->save();

        $this->artisan('demo:reset')->assertExitCode(0);

        $this->assertSame(OrderStatus::Pending, $recent->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $delivered->fresh()->status);
    }

    public function test_it_never_touches_other_customers(): void
    {
        $this->demo();
        $other = Customer::factory()->create();
        $order = $this->placeOrder($other, BookVariant::factory()->create(['stock_quantity' => 10]), 1, 25);
        $this->addToCart($other, BookVariant::factory()->create(['stock_quantity' => 5]));

        $this->artisan('demo:reset')->assertExitCode(0);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(1, $other->cart()->first()->items()->count());
    }

    public function test_it_does_nothing_without_the_demo_account(): void
    {
        $this->assertSame('demo@bookly.test', DemoSeeder::EMAIL);
        $this->artisan('demo:reset')->expectsOutputToContain('No demo account')->assertExitCode(0);
    }
}
