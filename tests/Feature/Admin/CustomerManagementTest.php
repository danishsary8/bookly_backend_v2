<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\OrderReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    public function test_staff_search_and_view_customer_with_stats(): void
    {
        $dara = Customer::factory()->create(['name' => 'Sok Dara', 'email' => 'dara@example.com', 'google_id' => 'g-1']);
        Customer::factory()->unverified()->create(['name' => 'Other']);
        $delivered = $this->deliveredOrder($dara, [[$this->physical('20.00'), 2]]);
        $pending = $this->deliveredOrder($dara, [[$this->physical('5.00'), 1]]);
        $pending->update(['status' => OrderStatus::Pending]);
        OrderReturn::create(['order_id' => $delivered->id, 'customer_id' => $dara->id, 'reason' => 'x', 'status' => 'refunded',
            'refund_amount' => '15.00', 'requested_at' => now(), 'resolved_at' => now()]);
        $staff = $this->staffToken();

        $this->asToken($staff)->getJson('/api/v1/staff/customers?q=dara')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.orders_count', 2)
            ->assertJsonPath('data.0.login_methods', ['password', 'google']);
        $this->asToken($staff)->getJson('/api/v1/staff/customers?verified=0')->assertJsonCount(1, 'data');

        $this->asToken($staff)->getJson("/api/v1/staff/customers/{$dara->id}")->assertOk()
            ->assertJsonPath('data.stats.lifetime_spent_usd', '25.00') // 40.00 delivered - 15.00 refunded
            ->assertJsonPath('data.stats.orders_by_status.delivered', 1)
            ->assertJsonPath('data.stats.orders_by_status.pending', 1)
            ->assertJsonPath('data.stats.returns_count', 1)
            ->assertJsonCount(2, 'data.recent_orders')
            ->assertJsonMissingPath('data.password_hash');
    }

    public function test_only_admin_deactivates_and_it_logs_the_customer_out(): void
    {
        $customer = Customer::factory()->create();
        $customerToken = $customer->createToken('t', ['customer'])->plainTextToken;
        $staff = $this->staffToken();
        $admin = $this->staffToken(admin: true);

        $this->asToken($staff)->postJson("/api/v1/staff/customers/{$customer->id}/deactivate")->assertForbidden();
        $this->asToken($admin)->postJson("/api/v1/staff/customers/{$customer->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

        $this->asToken($customerToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->asToken($admin)->postJson("/api/v1/staff/customers/{$customer->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'customer.deactivated', 'entity_id' => $customer->id]);
    }

    public function test_customers_cannot_use_customer_management(): void
    {
        $token = Customer::factory()->create()->createToken('t', ['customer'])->plainTextToken;

        $this->asToken($token)->getJson('/api/v1/staff/customers')->assertForbidden();
    }
}
