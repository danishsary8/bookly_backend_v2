<?php

namespace Tests\Feature\Orders;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\StaffUser;
use App\Notifications\OrderReadyToShipNotification;
use App\Notifications\PreorderDelayedNotification;
use App\Notifications\PreorderReleasedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class PreorderBackorderTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private Customer $customer;

    private CustomerAddress $address;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['shop.shipping_fees' => ['phnom_penh' => '1.50', 'provinces' => '3.00']]);
        $this->customer = Customer::factory()->create();
        $this->address = CustomerAddress::factory()->for($this->customer)->create(['city' => 'Phnom Penh']);
        $this->withToken($this->customer->createToken('t', ['customer'])->plainTextToken);
    }

    private function addToCart(BookVariant $variant, int $quantity = 1): void
    {
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => $quantity])->assertCreated();
    }

    private function checkout(array $body = [], string $key = 'checkout-key-preorder')
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', [
            'address_id' => $this->address->id, 'payment_method' => 'cod', ...$body,
        ]);
    }

    public function test_preorder_checkout_creates_waiting_order_with_future_date(): void
    {
        $futureDate = Carbon::today()->addDays(20)->toDateString();
        $preorderVariant = BookVariant::factory()->format(BookFormat::Hardcover)->create([
            'price_usd' => '25.00',
            'stock_quantity' => 0,
            'release_date' => $futureDate,
            'allow_backorder' => false,
        ]);

        $this->addToCart($preorderVariant, 1);

        $res = $this->checkout([], 'checkout-preorder-1')->assertCreated()
            ->assertJsonPath('data.is_waiting_stock', true)
            ->assertJsonPath('data.expected_ship_date', $futureDate)
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.items.0.is_preorder', true)
            ->assertJsonPath('data.items.0.is_backorder', false)
            ->assertJsonPath('data.items.0.expected_date', $futureDate);

        // Variant stock decremented into negative
        $this->assertSame(-1, $preorderVariant->fresh()->stock_quantity);

        $order = Order::find($res->json('data.id'));
        $this->assertTrue($order->is_waiting_stock);
        $this->assertSame($futureDate, $order->expected_ship_date->toDateString());
    }

    public function test_backorder_checkout_allows_zero_stock_and_goes_negative(): void
    {
        $backorderVariant = BookVariant::factory()->format(BookFormat::Paperback)->create([
            'price_usd' => '18.00',
            'stock_quantity' => 0,
            'release_date' => null,
            'allow_backorder' => true,
        ]);

        $this->addToCart($backorderVariant, 2);

        $res = $this->checkout([], 'checkout-backorder-1')->assertCreated()
            ->assertJsonPath('data.is_waiting_stock', true)
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.items.0.is_preorder', false)
            ->assertJsonPath('data.items.0.is_backorder', true);

        $this->assertSame(-2, $backorderVariant->fresh()->stock_quantity);

        $order = Order::find($res->json('data.id'));
        $this->assertTrue($order->is_waiting_stock);
    }

    public function test_normal_out_of_stock_variant_cannot_be_added_or_oversold(): void
    {
        $normalVariant = BookVariant::factory()->format(BookFormat::Paperback)->create([
            'price_usd' => '15.00',
            'stock_quantity' => 0,
            'release_date' => null,
            'allow_backorder' => false,
        ]);

        $this->postJson('/api/v1/cart/items', [
            'book_variant_id' => $normalVariant->id,
            'quantity' => 1,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_mixed_cart_checkout_has_single_shipping_and_waiting_status(): void
    {
        $inStock = BookVariant::factory()->format(BookFormat::Paperback)->create([
            'price_usd' => '12.00',
            'stock_quantity' => 10,
        ]);

        $futureDate = Carbon::today()->addDays(14)->toDateString();
        $preorder = BookVariant::factory()->format(BookFormat::Hardcover)->create([
            'price_usd' => '30.00',
            'stock_quantity' => 0,
            'release_date' => $futureDate,
        ]);

        $this->addToCart($inStock, 1);
        $this->addToCart($preorder, 1);

        // Preview returns shipping notice
        $preview = $this->postJson('/api/v1/checkout/preview', ['address_id' => $this->address->id])->assertOk();
        $preview->assertJsonPath('data.has_preorder', true);
        $preview->assertJsonPath('data.expected_ship_date', $futureDate);
        $this->assertStringContainsString('Ships when all items are ready', $preview->json('data.shipping_notice'));

        // Checkout
        $res = $this->checkout([], 'checkout-mixed-1')->assertCreated()
            ->assertJsonPath('data.is_waiting_stock', true)
            ->assertJsonPath('data.expected_ship_date', $futureDate);

        $order = Order::find($res->json('data.id'));
        $this->assertTrue($order->is_waiting_stock);
        $this->assertSame(9, $inStock->fresh()->stock_quantity);
        $this->assertSame(-1, $preorder->fresh()->stock_quantity);
    }

    public function test_fifo_stock_allocation_when_admin_restocks(): void
    {
        $token = $this->staffToken(admin: true);
        $variant = BookVariant::factory()->format(BookFormat::Paperback)->create([
            'price_usd' => '20.00',
            'stock_quantity' => 0,
            'allow_backorder' => true,
        ]);

        // Customer A orders 2 copies
        $this->addToCart($variant, 2);
        $resA = $this->checkout([], 'order-a-12345')->assertCreated();
        $orderA = Order::find($resA->json('data.id'));
        $orderA->update(['status' => OrderStatus::Processing, 'placed_at' => now()->subMinutes(10)]);

        // Customer B orders 1 copy
        $customerB = Customer::factory()->create();
        $addressB = CustomerAddress::factory()->for($customerB)->create();
        $tokenB = $customerB->createToken('tb', ['customer'])->plainTextToken;
        $this->asToken($tokenB)->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => 1])->assertCreated();
        $resB = $this->asToken($tokenB)->withHeader('Idempotency-Key', 'order-b-12345')->postJson('/api/v1/checkout', [
            'address_id' => $addressB->id, 'payment_method' => 'cod',
        ])->assertCreated();
        $orderB = Order::find($resB->json('data.id'));
        $orderB->update(['status' => OrderStatus::Processing, 'placed_at' => now()->subMinutes(5)]);

        $this->assertSame(-3, $variant->fresh()->stock_quantity);
        $this->assertTrue($orderA->fresh()->is_waiting_stock);
        $this->assertTrue($orderB->fresh()->is_waiting_stock);

        // Admin restocks 2 copies
        $this->asToken($token)
            ->postJson("/api/v1/staff/variants/{$variant->id}/restock", [
                'quantity' => 2,
                'supplier' => 'Phnom Penh Press',
            ])->assertOk();

        // Variant stock is now -1 (-3 + 2)
        $this->assertSame(-1, $variant->fresh()->stock_quantity);

        // Order A (demanded 2) was placed first -> Allocated!
        $this->assertFalse($orderA->fresh()->is_waiting_stock);

        // Order B (demanded 1) was placed second -> Still waiting!
        $this->assertTrue($orderB->fresh()->is_waiting_stock);

        // Staff was notified for Order A
        Notification::assertSentTo(StaffUser::all(), OrderReadyToShipNotification::class, function ($n) use ($orderA) {
            return $n->order->id === $orderA->id;
        });
    }

    public function test_check_releases_command_dispatches_customer_release_email_and_allocates_order(): void
    {
        $today = Carbon::today()->toDateString();
        $variant = BookVariant::factory()->format(BookFormat::Hardcover)->create([
            'price_usd' => '30.00',
            'stock_quantity' => 5, // arrived already or has stock
            'release_date' => $today,
        ]);

        $this->addToCart($variant, 1);
        $res = $this->checkout([], 'order-release-check')->assertCreated();
        $order = Order::find($res->json('data.id'));
        $order->update(['is_waiting_stock' => true, 'status' => OrderStatus::Processing]);

        $this->artisan('preorders:check-releases')->assertSuccessful();

        // Customer receives release email
        Notification::assertSentTo($this->customer, PreorderReleasedNotification::class, function ($n) use ($order, $variant) {
            return $n->order->id === $order->id && $n->variant->id === $variant->id;
        });

        // Order is allocated
        $this->assertFalse($order->fresh()->is_waiting_stock);
    }

    public function test_customer_can_cancel_waiting_order_before_shipping_and_stock_is_restored(): void
    {
        $variant = BookVariant::factory()->format(BookFormat::Paperback)->create([
            'price_usd' => '15.00',
            'stock_quantity' => 0,
            'allow_backorder' => true,
        ]);

        $this->addToCart($variant, 1);
        $res = $this->checkout([], 'order-cancel-test')->assertCreated();
        $order = Order::find($res->json('data.id'));
        $this->assertSame(-1, $variant->fresh()->stock_quantity);

        // Move to processing (paid/processing waiting order)
        $order->update(['status' => OrderStatus::Processing]);
        $this->assertTrue($order->fresh()->is_waiting_stock);

        // Customer cancels before shipping
        $this->postJson("/api/v1/orders/{$order->id}/cancel", [
            'reason' => 'Changed my mind',
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertFalse($order->fresh()->is_waiting_stock);

        // Stock restored back to 0
        $this->assertSame(0, $variant->fresh()->stock_quantity);
    }

    public function test_staff_updates_release_date_delays_and_notifies_customers(): void
    {
        $token = $this->staffToken(admin: true);
        $initialDate = Carbon::today()->addDays(10)->toDateString();
        $delayedDate = Carbon::today()->addDays(25)->toDateString();

        $variant = BookVariant::factory()->format(BookFormat::Hardcover)->create([
            'price_usd' => '25.00',
            'stock_quantity' => 0,
            'release_date' => $initialDate,
        ]);

        $this->addToCart($variant, 1);
        $res = $this->checkout([], 'order-delay-test')->assertCreated();
        $order = Order::find($res->json('data.id'));

        // Staff updates variant with a later release date
        $this->asToken($token)
            ->patchJson("/api/v1/staff/variants/{$variant->id}", [
                'release_date' => $delayedDate,
                'delay_note' => 'Publisher delayed container shipment',
            ])->assertOk();

        // Customer receives delay notification
        Notification::assertSentTo($this->customer, PreorderDelayedNotification::class, function ($n) use ($order, $delayedDate) {
            return $n->order->id === $order->id && $n->newDate === $delayedDate;
        });

        // Expected dates updated
        $this->assertSame($delayedDate, $order->fresh()->expected_ship_date->toDateString());
        $this->assertSame($delayedDate, $order->fresh()->items->first()->expected_date->toDateString());
    }

    public function test_staff_can_filter_orders_by_waiting_status(): void
    {
        $token = $this->staffToken(admin: true);
        $waitingOrder = Order::factory()->create([
            'is_waiting_stock' => true,
            'status' => OrderStatus::Processing,
        ]);
        $normalOrder = Order::factory()->create([
            'is_waiting_stock' => false,
            'status' => OrderStatus::Processing,
        ]);

        $res = $this->asToken($token)
            ->getJson('/api/v1/staff/orders?status=waiting')
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($waitingOrder->id, $ids);
        $this->assertNotContains($normalOrder->id, $ids);
    }
}
