<?php

namespace Tests\Feature\Platform;

use App\Enums\OrderStatus;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Services\Orders\OrderStatusService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
    private function placeOrder(Customer $customer, BookVariant $variant, int $qty, int $minutesAgo): Order
    {
        $this->withToken($customer->createToken('t', ['customer'])->plainTextToken);
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => $qty])->assertCreated();
        $address = CustomerAddress::factory()->for($customer)->create();
        $id = $this->withHeader('Idempotency-Key', 'demo-reset-key-'.(++self::$key))->postJson('/api/v1/checkout', [
            'address_id' => $address->id, 'payment_method' => 'cod',
        ])->assertCreated()->json('data.id');

        $order = Order::findOrFail($id);
        $order->forceFill(['placed_at' => now()->subMinutes($minutesAgo)])->save();

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
        $order = $this->placeOrder($demo, $variant, 2, 90);
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
        $recent = $this->placeOrder($demo, BookVariant::factory()->create(['stock_quantity' => 10]), 1, 30);
        $delivered = $this->placeOrder($demo, BookVariant::factory()->create(['stock_quantity' => 10]), 1, 3 * 24 * 60);
        $delivered->forceFill(['status' => OrderStatus::Delivered])->save();

        $this->artisan('demo:reset')->assertExitCode(0);

        $this->assertSame(OrderStatus::Pending, $recent->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $delivered->fresh()->status);
    }

    public function test_it_never_touches_other_customers(): void
    {
        $this->demo();
        $other = Customer::factory()->create();
        $order = $this->placeOrder($other, BookVariant::factory()->create(['stock_quantity' => 10]), 1, 90);
        $this->addToCart($other, BookVariant::factory()->create(['stock_quantity' => 5]));

        $this->artisan('demo:reset')->assertExitCode(0);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(1, $other->cart()->first()->items()->count());
    }

    public function test_one_order_that_cannot_be_cancelled_does_not_stop_the_others(): void
    {
        $demo = $this->demo();
        $first = $this->placeOrder($demo, BookVariant::factory()->create(['stock_quantity' => 10]), 1, 90);
        $second = $this->placeOrder($demo, BookVariant::factory()->create(['stock_quantity' => 10]), 1, 90);

        // The first cancel fails (e.g. staff moved the order on a moment earlier); the second must still be cancelled.
        $real = app(OrderStatusService::class);
        $this->mock(OrderStatusService::class, function ($mock) use ($real, $first) {
            $mock->shouldReceive('cancelByCustomer')->andReturnUsing(function ($order, $reason) use ($real, $first) {
                if ($order->id === $first->id) {
                    throw new \RuntimeException('Order is no longer pending.');
                }

                return $real->cancelByCustomer($order, $reason);
            });
        });

        $this->artisan('demo:reset')->expectsOutputToContain('1 stale order(s) cancelled')->assertExitCode(0);

        $this->assertSame(OrderStatus::Pending, $first->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $second->fresh()->status);
    }

    public function test_it_restores_the_demo_name_and_password(): void
    {
        $demo = $this->demo();
        $demo->forceFill(['name' => 'Someone else', 'password_hash' => 'changed-Pass1'])->save();

        $this->artisan('demo:reset')->assertExitCode(0);

        $demo->refresh();
        $this->assertSame('Demo Customer', $demo->name);
        $this->assertTrue(Hash::check(DemoSeeder::PASSWORD, $demo->password_hash));
    }

    public function test_it_does_nothing_without_the_demo_account(): void
    {
        $this->assertSame('demo@bookly.test', DemoSeeder::EMAIL);
        $this->artisan('demo:reset')->expectsOutputToContain('No demo account')->assertExitCode(0);
    }
}
