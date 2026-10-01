<?php

namespace Tests\Feature\Orders;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Models\BookVariant;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CustomerOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->customer = Customer::factory()->create();
        $this->withToken($this->customer->createToken('t', ['customer'])->plainTextToken);
    }

    private function placeOrder(array $variants, ?string $coupon = null, string $key = 'order-key-0001'): int
    {
        foreach ($variants as [$variant, $qty]) {
            $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => $qty])->assertCreated();
        }
        $address = CustomerAddress::factory()->for($this->customer)->create();

        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', [
            'address_id' => $address->id, 'payment_method' => 'cod', 'coupon_code' => $coupon,
        ])->assertCreated()->json('data.id');
    }

    public function test_list_and_show_own_orders(): void
    {
        $id = $this->placeOrder([[BookVariant::factory()->create(), 1]]);
        Order::factory()->create(); // someone else's

        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/orders?status=delivered')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/orders/{$id}")->assertOk()->assertJsonPath('data.status_history.0.status', 'pending');
        $this->getJson('/api/v1/orders/'.Order::where('id', '!=', $id)->value('id'))->assertNotFound();
    }

    public function test_cancel_pending_order_restores_stock_coupon_and_payment(): void
    {
        $paperback = BookVariant::factory()->create(['stock_quantity' => 10]);
        $ebook = BookVariant::factory()->format(BookFormat::Ebook)->create(['stock_quantity' => 0]);
        $coupon = Coupon::factory()->create(['code' => 'TENOFF']);
        $id = $this->placeOrder([[$paperback, 3], [$ebook, 1]], 'TENOFF');
        $this->assertSame(7, $paperback->fresh()->stock_quantity);

        $this->postJson("/api/v1/orders/{$id}/cancel", ['reason' => 'Ordered by mistake'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment_status', 'failed')
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.status_history.1.note', 'Cancelled by customer: Ordered by mistake');

        $this->assertSame(10, $paperback->fresh()->stock_quantity);
        $this->assertSame(0, $ebook->fresh()->stock_quantity);
        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertDatabaseHas('inventory_movements', ['book_variant_id' => $paperback->id, 'change_qty' => 3, 'reason' => 'adjustment', 'reference_id' => $id]);

        // The coupon use is given back, so it can be used again.
        $this->placeOrder([[$paperback, 1]], 'TENOFF', 'order-key-0002');
    }

    public function test_customer_cannot_cancel_after_pending(): void
    {
        $id = $this->placeOrder([[BookVariant::factory()->create(), 1]]);
        Order::whereKey($id)->update(['status' => OrderStatus::Processing]);

        $this->postJson("/api/v1/orders/{$id}/cancel")->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'This order can no longer be cancelled. Please contact support.');
    }

    public function test_cancelling_twice_is_rejected(): void
    {
        $id = $this->placeOrder([[BookVariant::factory()->create(['stock_quantity' => 5]), 2]]);

        $this->postJson("/api/v1/orders/{$id}/cancel")->assertOk();
        $this->postJson("/api/v1/orders/{$id}/cancel")->assertUnprocessable();

        $this->assertSame(5, BookVariant::sole()->stock_quantity, 'stock is restored only once');
    }
}
