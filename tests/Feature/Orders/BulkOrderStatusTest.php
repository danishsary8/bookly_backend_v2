<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AdminAuditLog;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Notifications\OrderStatusChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class BulkOrderStatusTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private BookVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->variant = BookVariant::factory()->create(['stock_quantity' => 50]);
    }

    private function placeOrder(string $email): int
    {
        $customer = Customer::factory()->create(['email' => $email]);
        $address = CustomerAddress::factory()->for($customer)->create();
        $this->asToken($customer->createToken('t', ['customer'])->plainTextToken)
            ->postJson('/api/v1/cart/items', ['book_variant_id' => $this->variant->id, 'quantity' => 1])->assertCreated();

        return $this->withHeader('Idempotency-Key', 'key-'.md5($email))->postJson('/api/v1/checkout', [
            'address_id' => $address->id, 'payment_method' => 'cod',
        ])->assertCreated()->json('data.id');
    }

    private function bulk(string $token, array $body)
    {
        return $this->asToken($token)->postJson('/api/v1/staff/orders/bulk-status', $body);
    }

    public function test_moves_several_orders_with_history_audit_and_customer_emails(): void
    {
        $ids = [$this->placeOrder('a@example.com'), $this->placeOrder('b@example.com'), $this->placeOrder('c@example.com')];
        Notification::fake(); // only count what the bulk change sends
        $staff = $this->staffToken();

        $this->bulk($staff, ['ids' => $ids, 'status' => 'processing', 'note' => 'Packing today'])->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonCount(3, 'data.updated')
            ->assertJsonPath('data.updated.0.id', $ids[0])
            ->assertJsonPath('data.updated.0.status', 'processing')
            ->assertJsonPath('data.failed', []);

        foreach ($ids as $id) {
            $order = Order::find($id);
            $this->assertSame(OrderStatus::Processing, $order->status);
            $this->assertSame('Packing today', $order->statusHistory->last()->note);
            $this->assertNotNull($order->statusHistory->last()->changed_by_staff_id);
            Notification::assertSentTo($order->customer, OrderStatusChangedNotification::class);
        }
        $this->assertSame(3, AdminAuditLog::where('action', 'order.status_changed')->count());
    }

    public function test_orders_that_cannot_move_stay_as_they_were_and_are_listed_with_the_reason(): void
    {
        $pending = $this->placeOrder('a@example.com');
        $processing = $this->placeOrder('b@example.com');
        $staff = $this->staffToken();
        $this->asToken($staff)->postJson("/api/v1/staff/orders/{$processing}/status", ['status' => 'processing'])->assertOk();
        $pendingNumber = Order::find($pending)->order_number;

        $this->bulk($staff, ['ids' => [$pending, 999999, $processing], 'status' => 'shipped'])->assertOk()
            ->assertJsonPath('data.updated', [['id' => $processing, 'order_number' => Order::find($processing)->order_number, 'status' => 'shipped']])
            ->assertJsonPath('data.failed.0', ['id' => $pending, 'order_number' => $pendingNumber, 'reason' => 'An order cannot move from pending to shipped.'])
            ->assertJsonPath('data.failed.1', ['id' => 999999, 'order_number' => null, 'reason' => 'Order not found.']);

        $this->assertSame(OrderStatus::Pending, Order::find($pending)->status);
        $this->assertSame(OrderStatus::Shipped, Order::find($processing)->status);
    }

    public function test_bulk_delivered_records_the_cash_as_collected(): void
    {
        $ids = [$this->placeOrder('a@example.com'), $this->placeOrder('b@example.com')];
        $staff = $this->staffToken();
        foreach (['processing', 'shipped', 'delivered'] as $status) {
            $this->bulk($staff, ['ids' => $ids, 'status' => $status])->assertOk()->assertJsonCount(2, 'data.updated');
        }

        foreach ($ids as $id) {
            $this->assertSame(PaymentStatus::Succeeded, Order::find($id)->payments->first()->status);
        }
    }

    public function test_cancel_is_never_done_in_bulk(): void
    {
        $id = $this->placeOrder('a@example.com');

        $this->bulk($this->staffToken(), ['ids' => [$id], 'status' => 'cancelled'])->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Bulk changes can start processing, mark as shipped or mark as delivered. Cancel orders one at a time.');
        $this->assertSame(OrderStatus::Pending, Order::find($id)->status);
    }

    public function test_the_request_is_validated(): void
    {
        $staff = $this->staffToken();

        $this->bulk($staff, ['status' => 'processing'])->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->bulk($staff, ['ids' => range(1, 51), 'status' => 'processing'])->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->bulk($staff, ['ids' => [1, 1], 'status' => 'processing'])->assertUnprocessable()->assertJsonValidationErrors('ids.0');
        $this->bulk($staff, ['ids' => [1]])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->bulk($staff, ['ids' => [1], 'status' => 'processing', 'note' => str_repeat('x', 501)])->assertUnprocessable()->assertJsonValidationErrors('note');
    }

    public function test_only_staff_can_change_orders_in_bulk(): void
    {
        $id = $this->placeOrder('a@example.com');
        $customerToken = Customer::first()->createToken('t', ['customer'])->plainTextToken;

        $this->bulk($customerToken, ['ids' => [$id], 'status' => 'processing'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->postJson('/api/v1/staff/orders/bulk-status', ['ids' => [$id], 'status' => 'processing'])->assertUnauthorized();
        $this->assertSame(OrderStatus::Pending, Order::find($id)->status);
    }
}
