<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\AdminAuditLog;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class StaffOrdersTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private BookVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->variant = BookVariant::factory()->create(['stock_quantity' => 10]);
    }

    private function placeOrder(string $email = 'dara@example.com', int $qty = 2): int
    {
        $customer = Customer::factory()->create(['email' => $email]);
        $address = CustomerAddress::factory()->for($customer)->create();
        $this->asToken($customer->createToken('t', ['customer'])->plainTextToken)
            ->postJson('/api/v1/cart/items', ['book_variant_id' => $this->variant->id, 'quantity' => $qty])->assertCreated();

        return $this->withHeader('Idempotency-Key', 'key-'.md5($email))->postJson('/api/v1/checkout', [
            'address_id' => $address->id, 'payment_method' => 'cod',
        ])->assertCreated()->json('data.id');
    }

    private function setStatus(string $token, int $id, string $status, ?string $note = null)
    {
        return $this->asToken($token)->postJson("/api/v1/staff/orders/{$id}/status", ['status' => $status, 'note' => $note]);
    }

    public function test_full_cod_flow_to_delivered_marks_payment_succeeded(): void
    {
        $id = $this->placeOrder();
        $staff = $this->staffToken();

        $this->setStatus($staff, $id, 'processing')->assertOk()->assertJsonPath('data.allowed_next_statuses', ['shipped', 'cancelled']);
        $this->setStatus($staff, $id, 'shipped', 'Courier: J&T, tracking 123')->assertOk()->assertJsonPath('data.allowed_next_statuses', ['delivered']);
        $this->setStatus($staff, $id, 'delivered')->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.payment_status', 'succeeded')
            ->assertJsonCount(4, 'data.status_history')
            ->assertJsonPath('data.status_history.2.note', 'Courier: J&T, tracking 123')
            ->assertJsonPath('data.allowed_next_statuses', []);

        $this->assertSame(3, AdminAuditLog::where('action', 'order.status_changed')->count());
        $this->assertNotNull(Order::find($id)->statusHistory->last()->changed_by_staff_id);
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $id = $this->placeOrder();
        $staff = $this->staffToken();

        $this->setStatus($staff, $id, 'shipped')->assertUnprocessable()->assertJsonPath('errors.status.0', 'An order cannot move from pending to shipped.');
        $this->setStatus($staff, $id, 'paid')->assertUnprocessable()->assertJsonPath('errors.status.0', 'Staff cannot set an order to paid.');
        $this->setStatus($staff, $id, 'returned')->assertUnprocessable();
        $this->setStatus($staff, $id, 'unknown')->assertUnprocessable();
    }

    public function test_staff_can_cancel_before_shipping_but_not_after(): void
    {
        $processing = $this->placeOrder('a@example.com', 2);
        $shipped = $this->placeOrder('b@example.com', 3);
        $staff = $this->staffToken();
        $this->setStatus($staff, $processing, 'processing');
        $this->setStatus($staff, $shipped, 'processing');
        $this->setStatus($staff, $shipped, 'shipped');
        $this->assertSame(5, $this->variant->fresh()->stock_quantity);

        $this->setStatus($staff, $processing, 'cancelled', 'Out of stock at warehouse')->assertOk()->assertJsonPath('data.payment_status', 'failed');
        $this->setStatus($staff, $shipped, 'cancelled')->assertUnprocessable();

        $this->assertSame(7, $this->variant->fresh()->stock_quantity);
        $this->assertDatabaseHas('inventory_movements', ['reference_id' => $processing, 'reason' => 'adjustment', 'change_qty' => 2, 'created_by_staff_id' => Order::find($processing)->statusHistory->last()->changed_by_staff_id]);
    }

    public function test_list_filters_and_detail(): void
    {
        $dara = $this->placeOrder('dara@example.com');
        $sok = $this->placeOrder('sok@example.com');
        $staff = $this->staffToken();
        $this->setStatus($staff, $sok, 'processing');
        $number = Order::find($dara)->order_number;

        $this->asToken($staff)->getJson('/api/v1/staff/orders')->assertOk()->assertJsonCount(2, 'data');
        $this->asToken($staff)->getJson('/api/v1/staff/orders?status=processing')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sok);
        $this->asToken($staff)->getJson('/api/v1/staff/orders?q=dara@')->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer.email', 'dara@example.com');
        $this->asToken($staff)->getJson('/api/v1/staff/orders?q='.$number)->assertJsonCount(1, 'data');
        $this->asToken($staff)->getJson('/api/v1/staff/orders?from='.now()->addDay()->toDateString())->assertJsonCount(0, 'data');
        $this->asToken($staff)->getJson("/api/v1/staff/orders/{$sok}")->assertOk()
            ->assertJsonPath('data.status_history.1.changed_by.name', fn ($name) => is_string($name));
    }

    public function test_customers_cannot_use_staff_order_routes(): void
    {
        $id = $this->placeOrder();
        $customer = Customer::factory()->create()->createToken('t', ['customer'])->plainTextToken;

        $this->asToken($customer)->getJson('/api/v1/staff/orders')->assertForbidden();
        $this->asToken($customer)->postJson("/api/v1/staff/orders/{$id}/status", ['status' => 'processing'])->assertForbidden();
        $this->assertSame(OrderStatus::Pending, Order::find($id)->status);
    }
}
