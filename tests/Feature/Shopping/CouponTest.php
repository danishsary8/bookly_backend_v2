<?php

namespace Tests\Feature\Shopping;

use App\Enums\CouponType;
use App\Enums\OrderStatus;
use App\Models\AdminAuditLog;
use App\Models\BookVariant;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    // ---- staff side ----

    public function test_staff_creates_coupon_with_normalized_code_and_audit(): void
    {
        $token = $this->staffToken();

        $this->asToken($token)->postJson('/api/v1/staff/coupons', [
            'code' => ' welcome10 ', 'type' => 'percentage', 'value' => '10', 'min_order_amount' => '20', 'max_uses' => 100,
        ])->assertCreated()->assertJsonPath('data.code', 'WELCOME10')->assertJsonPath('data.used_count', 0);

        $this->asToken($token)->postJson('/api/v1/staff/coupons', ['code' => 'Welcome10', 'type' => 'fixed', 'value' => '5'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'coupon.created']);
    }

    public function test_coupon_validation_rules(): void
    {
        $token = $this->staffToken();

        $this->asToken($token)->postJson('/api/v1/staff/coupons', [
            'code' => 'BAD', 'type' => 'percentage', 'value' => '150', 'starts_at' => '2026-12-01', 'expires_at' => '2026-11-01',
        ])->assertUnprocessable()->assertJsonValidationErrors(['value', 'expires_at']);

        $this->asToken($token)->postJson('/api/v1/staff/coupons', ['code' => 'X!', 'type' => 'other', 'value' => '0'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'type', 'value']);
    }

    public function test_staff_lists_and_updates_coupons(): void
    {
        $token = $this->staffToken();
        $coupon = Coupon::factory()->create(['code' => 'SUMMER', 'is_active' => true]);
        Coupon::factory()->create(['code' => 'WINTER', 'is_active' => false]);

        $this->asToken($token)->getJson('/api/v1/staff/coupons?q=summ')->assertJsonCount(1, 'data');
        $this->asToken($token)->getJson('/api/v1/staff/coupons?active=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'WINTER');
        $this->asToken($token)->patchJson("/api/v1/staff/coupons/{$coupon->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

        $log = AdminAuditLog::where('action', 'coupon.updated')->sole();
        $this->assertSame(['is_active' => false], $log->after_data);
    }

    public function test_only_admin_deletes_and_used_coupons_are_protected(): void
    {
        $staff = $this->staffToken();
        $admin = $this->staffToken(admin: true);
        $unused = Coupon::factory()->create();
        $used = Coupon::factory()->create();
        Order::factory()->create(['coupon_id' => $used->id]);

        $this->asToken($staff)->deleteJson("/api/v1/staff/coupons/{$unused->id}")->assertForbidden();
        $this->asToken($admin)->deleteJson("/api/v1/staff/coupons/{$used->id}")->assertStatus(409);
        $this->asToken($admin)->deleteJson("/api/v1/staff/coupons/{$unused->id}")->assertNoContent();
    }

    // ---- customer side ----

    private function customerWithCart(string $price = '20.00', int $qty = 1): Customer
    {
        $customer = Customer::factory()->create();
        $this->asToken($customer->createToken('t', ['customer'])->plainTextToken);
        $variant = BookVariant::factory()->create(['price_usd' => $price]);
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => $qty])->assertCreated();

        return $customer;
    }

    public function test_customer_previews_percentage_and_fixed_discounts(): void
    {
        Coupon::factory()->create(['code' => 'TENOFF', 'type' => CouponType::Percentage, 'value' => '10']);
        Coupon::factory()->create(['code' => 'BIG', 'type' => CouponType::Fixed, 'value' => '100']);
        $this->customerWithCart('19.99', 2);

        $this->postJson('/api/v1/cart/coupon/check', ['code' => 'tenoff'])->assertOk()
            ->assertJsonPath('data.subtotal_usd', '39.98')
            ->assertJsonPath('data.discount_usd', '3.99')
            ->assertJsonPath('data.total_before_shipping_usd', '35.99');

        // A fixed discount never goes below zero.
        $this->postJson('/api/v1/cart/coupon/check', ['code' => 'BIG'])->assertOk()
            ->assertJsonPath('data.discount_usd', '39.98')->assertJsonPath('data.total_before_shipping_usd', '0.00');
    }

    public function test_coupon_rejections(): void
    {
        Coupon::factory()->create(['code' => 'OFF', 'is_active' => false]);
        Coupon::factory()->create(['code' => 'OLD', 'expires_at' => now()->subDay()]);
        Coupon::factory()->create(['code' => 'SOON', 'starts_at' => now()->addDay()]);
        Coupon::factory()->create(['code' => 'FULL', 'max_uses' => 5, 'used_count' => 5]);
        Coupon::factory()->create(['code' => 'MIN50', 'min_order_amount' => '50']);
        $this->customerWithCart('20.00');

        foreach (['NOPE' => 'This coupon code is not valid.', 'OFF' => 'This coupon is not active.', 'OLD' => 'This coupon has expired.',
            'SOON' => 'This coupon is not valid yet.', 'FULL' => 'This coupon has reached its usage limit.',
            'MIN50' => 'Order subtotal must be at least 50.00.'] as $code => $message) {
            $this->postJson('/api/v1/cart/coupon/check', ['code' => $code])->assertUnprocessable()->assertJsonPath('errors.code.0', $message);
        }
    }

    public function test_one_use_per_customer_but_cancelled_orders_give_it_back(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'ONCE']);
        $customer = $this->customerWithCart();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'coupon_id' => $coupon->id, 'status' => OrderStatus::Delivered]);

        $this->postJson('/api/v1/cart/coupon/check', ['code' => 'ONCE'])->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'You have already used this coupon.');

        $order->update(['status' => OrderStatus::Cancelled]);
        $this->postJson('/api/v1/cart/coupon/check', ['code' => 'ONCE'])->assertOk();
    }

    public function test_empty_cart_and_rate_limit(): void
    {
        Coupon::factory()->create(['code' => 'TENOFF']);
        $this->asToken(Customer::factory()->create()->createToken('t', ['customer'])->plainTextToken);

        $this->postJson('/api/v1/cart/coupon/check', ['code' => 'TENOFF'])->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Add items to your cart before using a coupon.');

        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/v1/cart/coupon/check', ['code' => "GUESS{$i}"]);
        }
        $this->postJson('/api/v1/cart/coupon/check', ['code' => 'GUESS'])->assertTooManyRequests();
    }
}
