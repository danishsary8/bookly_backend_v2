<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\OrderReturn;
use App\Models\StaffUser;
use App\Services\Auth\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    public function test_closing_account_is_admin_only(): void
    {
        $customer = Customer::factory()->create();
        $staff = $this->staffToken(admin: false);

        $this->asToken($staff)->postJson("/api/v1/staff/customers/{$customer->id}/close", [
            'password' => 'password',
            'code' => '123456',
            'confirm' => $customer->email,
        ])->assertForbidden();
    }

    public function test_close_account_validates_password_and_totp_and_confirm(): void
    {
        $admin = StaffUser::factory()->admin()->withTwoFactor()->create(['password_hash' => 'SecretPass123!']);
        $token = $admin->createToken('test', $admin->tokenAbilities())->plainTextToken;
        $customer = Customer::factory()->create(['email' => 'client@example.com']);
        $totp = app(TotpService::class);
        $validCode = $totp->codeAt($admin->two_factor_secret, time());

        // Wrong password
        $this->asToken($token)->postJson("/api/v1/staff/customers/{$customer->id}/close", [
            'password' => 'WrongPassword',
            'code' => $validCode,
            'confirm' => 'client@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        // Wrong 2FA code
        $this->asToken($token)->postJson("/api/v1/staff/customers/{$customer->id}/close", [
            'password' => 'SecretPass123!',
            'code' => '000000',
            'confirm' => 'client@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);

        // Wrong confirm
        $this->asToken($token)->postJson("/api/v1/staff/customers/{$customer->id}/close", [
            'password' => 'SecretPass123!',
            'code' => $validCode,
            'confirm' => 'wrong@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors(['confirm']);
    }

    public function test_bad_2fa_code_triggers_lockout(): void
    {
        $admin = StaffUser::factory()->admin()->withTwoFactor()->create(['password_hash' => 'SecretPass123!']);
        $token = $admin->createToken('test', $admin->tokenAbilities())->plainTextToken;
        $customer = Customer::factory()->create(['email' => 'client@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->asToken($token)->postJson("/api/v1/staff/customers/{$customer->id}/close", [
                'password' => 'SecretPass123!',
                'code' => '000000',
                'confirm' => 'client@example.com',
            ])->assertStatus(422);
        }

        // 6th attempt is throttled with 429
        $this->asToken($token)->postJson("/api/v1/staff/customers/{$customer->id}/close", [
            'password' => 'SecretPass123!',
            'code' => '000000',
            'confirm' => 'client@example.com',
        ])->assertStatus(429);
    }

    public function test_open_orders_block_closure(): void
    {
        $admin = StaffUser::factory()->admin()->withTwoFactor()->create(['password_hash' => 'SecretPass123!']);
        $token = $admin->createToken('test', $admin->tokenAbilities())->plainTextToken;
        $customer = Customer::factory()->create(['email' => 'client@example.com']);
        $order = $this->deliveredOrder($customer, [[$this->physical('10.00'), 1]]);
        $order->update(['status' => OrderStatus::Processing]);

        $validCode = app(TotpService::class)->codeAt($admin->two_factor_secret, time());

        $this->asToken($token)->postJson("/api/v1/staff/customers/{$customer->id}/close", [
            'password' => 'SecretPass123!',
            'code' => $validCode,
            'confirm' => 'client@example.com',
        ])->assertStatus(422)->assertJsonPath('message', 'This customer has an order on its way. Wait until it is delivered or cancelled before closing the account.');
    }

    public function test_a_typo_or_an_open_order_does_not_use_up_the_code(): void
    {
        $admin = StaffUser::factory()->admin()->withTwoFactor()->create(['password_hash' => 'SecretPass123!']);
        $token = $admin->createToken('test', $admin->tokenAbilities())->plainTextToken;
        $customer = Customer::factory()->create(['email' => 'client@example.com']);
        $order = $this->deliveredOrder($customer, [[$this->physical('10.00'), 1]]);
        $order->update(['status' => OrderStatus::Shipped]);
        $code = app(TotpService::class)->codeAt($admin->two_factor_secret, time());
        $close = fn (string $confirm) => $this->asToken($token)->postJson("/api/v1/staff/customers/{$customer->id}/close", [
            'password' => 'SecretPass123!',
            'code' => $code,
            'confirm' => $confirm,
        ]);

        $close('client@example.co')->assertStatus(422)->assertJsonValidationErrors(['confirm'])->assertJsonMissingValidationErrors(['code']);
        $close('client@example.com')->assertStatus(422)->assertJsonPath('message', 'This customer has an order on its way. Wait until it is delivered or cancelled before closing the account.');

        $order->update(['status' => OrderStatus::Delivered]);

        // The same code still works once the typo is fixed and the order is delivered.
        $close('client@example.com')->assertOk();
        $this->assertSoftDeleted($customer);
    }

    public function test_admin_closes_customer_account_successfully(): void
    {
        config([
            'services.ops_alerts.token' => 'alert-token',
            'services.ops_alerts.chat_id' => 'owner-chat-123',
            'services.ops_alerts.staff_chat_id' => 'staff-group-456',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $admin = StaffUser::factory()->admin()->withTwoFactor()->create([
            'name' => 'Alice Admin',
            'password_hash' => 'SecretPass123!',
        ]);
        $token = $admin->createToken('test', $admin->tokenAbilities())->plainTextToken;
        $customer = Customer::factory()->create([
            'name' => 'Bob Customer',
            'email' => 'bob@example.com',
        ]);
        $customerToken = $customer->createToken('customer-auth', ['customer'])->plainTextToken;

        $validCode = app(TotpService::class)->codeAt($admin->two_factor_secret, time());

        $res = $this->asToken($token)->postJson("/api/v1/staff/customers/{$customer->id}/close", [
            'password' => 'SecretPass123!',
            'code' => $validCode,
            'confirm' => 'bob@example.com',
        ]);

        $res->assertOk();
        $this->assertNotNull($res->json('data.closed_at'));
        $this->assertNotNull($res->json('data.erase_at'));

        // Customer is soft-deleted
        $customer->refresh();
        $this->assertTrue($customer->trashed());

        // Tokens are revoked
        $this->asToken($customerToken)->getJson('/api/v1/me')->assertUnauthorized();

        // Audit entry recorded
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'customer.closed',
            'entity_id' => $customer->id,
            'staff_user_id' => $admin->id,
        ]);

        // OpsAlerts sent to OWNER chat only (not staff group)
        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            return $r['chat_id'] === 'owner-chat-123'
                && str_contains($r['text'], 'Customer account closed by Alice Admin: Bob Customer')
                && str_contains($r['text'], 'can be reopened until');
        });
    }
}
