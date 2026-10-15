<?php

namespace Tests\Feature\Returns;

use App\Enums\CouponType;
use App\Enums\ReturnStatus;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\OrderReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class StoreCreditRefundTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    private Customer $customer;

    private string $customerToken;

    private string $staffToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::factory()->create(['email' => 'customer1@example.com']);
        $this->customerToken = $this->customer->createToken('test', ['customer'])->plainTextToken;
        $this->staffToken = $this->staffToken();
    }

    private function approvedReturn(Customer $customer, int $qty = 1, string $price = '15.00'): OrderReturn
    {
        $variant = $this->physical($price);
        $order = $this->deliveredOrder($customer, [[$variant, $qty]]);

        $return = OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'reason' => 'Defective cover',
            'status' => ReturnStatus::Approved,
            'requested_at' => now()->subDay(),
        ]);

        $return->items()->create([
            'order_item_id' => $order->items->first()->id,
            'quantity' => $qty,
            'reason' => 'Defective cover',
        ]);

        return $return;
    }

    public function test_guest_and_customer_cannot_call_staff_refund(): void
    {
        $return = $this->approvedReturn($this->customer);

        $this->postJson("/api/v1/staff/returns/{$return->id}/refund", ['method' => 'store_credit'])
            ->assertUnauthorized();

        $this->asToken($this->customerToken)
            ->postJson("/api/v1/staff/returns/{$return->id}/refund", ['method' => 'store_credit'])
            ->assertForbidden();
    }

    public function test_refund_via_store_credit_creates_bound_coupon_and_records_reference(): void
    {
        $return = $this->approvedReturn($this->customer, 1, '20.00');

        $response = $this->asToken($this->staffToken)
            ->postJson("/api/v1/staff/returns/{$return->id}/refund", [
                'method' => 'store_credit',
                'note' => 'Issuing store credit as requested',
            ])
            ->assertOk();

        $response->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.refund_method', 'store_credit')
            ->assertJsonPath('data.refund_amount_usd', '20.00')
            ->assertJsonPath('data.staff_note', 'Issuing store credit as requested');

        $ref = $response->json('data.refund_reference');
        $this->assertNotNull($ref);
        $this->assertMatchesRegularExpression('/^CREDIT-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $ref);

        // Check coupon in database
        $coupon = Coupon::where('code', $ref)->first();
        $this->assertNotNull($coupon);
        $this->assertSame('fixed', $coupon->type->value);
        $this->assertSame('20.00', $coupon->value);
        $this->assertSame($this->customer->id, $coupon->user_id);
        $this->assertTrue($coupon->is_store_credit);
        $this->assertSame(1, $coupon->max_uses);
        $this->assertSame(0, $coupon->used_count);
        $this->assertTrue($coupon->is_active);

        // Customer sees credit in GET /me/store-credits
        $meRes = $this->asToken($this->customerToken)
            ->getJson('/api/v1/me/store-credits')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $meRes->assertJsonPath('data.0.code', $ref)
            ->assertJsonPath('data.0.value_usd', '20.00');

        // Customer can check this coupon when cart has items
        $cartVariant = $this->physical('30.00');
        $this->asToken($this->customerToken)
            ->postJson('/api/v1/cart/items', ['book_variant_id' => $cartVariant->id, 'quantity' => 1])
            ->assertCreated();

        $this->asToken($this->customerToken)
            ->postJson('/api/v1/cart/coupon/check', ['code' => $ref])
            ->assertOk()
            ->assertJsonPath('data.code', $ref)
            ->assertJsonPath('data.discount_usd', '20.00');

        // Another customer CANNOT check or use this coupon
        $otherCustomer = Customer::factory()->create(['email' => 'other@example.com']);
        $otherToken = $otherCustomer->createToken('test', ['customer'])->plainTextToken;

        $this->asToken($otherToken)
            ->postJson('/api/v1/cart/items', ['book_variant_id' => $cartVariant->id, 'quantity' => 1])
            ->assertCreated();

        $this->asToken($otherToken)
            ->postJson('/api/v1/cart/coupon/check', ['code' => $ref])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code')
            ->assertJsonPath('errors.code.0', 'This store credit is not valid for your account.');
    }

    public function test_refund_via_manual_offline_requires_reference(): void
    {
        $return = $this->approvedReturn($this->customer, 1, '15.00');

        // Missing reference fails with 422
        $this->asToken($this->staffToken)
            ->postJson("/api/v1/staff/returns/{$return->id}/refund", [
                'method' => 'manual_offline',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference');

        // With reference succeeds
        $this->asToken($this->staffToken)
            ->postJson("/api/v1/staff/returns/{$return->id}/refund", [
                'method' => 'manual_offline',
                'reference' => 'ABA-TX-12345678',
                'note' => 'Cash transferred via ABA QR',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.refund_method', 'manual_offline')
            ->assertJsonPath('data.refund_reference', 'ABA-TX-12345678')
            ->assertJsonPath('data.refund_amount_usd', '15.00');
    }

    public function test_cannot_refund_already_refunded_return(): void
    {
        $return = $this->approvedReturn($this->customer);

        $this->asToken($this->staffToken)
            ->postJson("/api/v1/staff/returns/{$return->id}/refund", [
                'method' => 'manual_offline',
                'reference' => 'REF-001',
            ])
            ->assertOk();

        // Second refund attempt fails
        $this->asToken($this->staffToken)
            ->postJson("/api/v1/staff/returns/{$return->id}/refund", [
                'method' => 'store_credit',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_store_credits_endpoint_filters_expired_and_used_credits(): void
    {
        // 1. Active valid credit
        Coupon::create([
            'code' => 'CREDIT-TEST-ACTV',
            'type' => CouponType::Fixed,
            'value' => '10.00',
            'user_id' => $this->customer->id,
            'is_store_credit' => true,
            'max_uses' => 1,
            'used_count' => 0,
            'expires_at' => now()->addMonths(6),
            'is_active' => true,
        ]);

        // 2. Expired credit
        Coupon::create([
            'code' => 'CREDIT-TEST-EXPR',
            'type' => CouponType::Fixed,
            'value' => '10.00',
            'user_id' => $this->customer->id,
            'is_store_credit' => true,
            'max_uses' => 1,
            'used_count' => 0,
            'expires_at' => now()->subDay(),
            'is_active' => true,
        ]);

        // 3. Used credit
        Coupon::create([
            'code' => 'CREDIT-TEST-USED',
            'type' => CouponType::Fixed,
            'value' => '10.00',
            'user_id' => $this->customer->id,
            'is_store_credit' => true,
            'max_uses' => 1,
            'used_count' => 1,
            'expires_at' => now()->addMonths(6),
            'is_active' => true,
        ]);

        // 4. Inactive credit
        Coupon::create([
            'code' => 'CREDIT-TEST-DACT',
            'type' => CouponType::Fixed,
            'value' => '10.00',
            'user_id' => $this->customer->id,
            'is_store_credit' => true,
            'max_uses' => 1,
            'used_count' => 0,
            'expires_at' => now()->addMonths(6),
            'is_active' => false,
        ]);

        // 5. Credit for someone else
        $other = Customer::factory()->create(['email' => 'other2@example.com']);
        Coupon::create([
            'code' => 'CREDIT-TEST-OTHR',
            'type' => CouponType::Fixed,
            'value' => '10.00',
            'user_id' => $other->id,
            'is_store_credit' => true,
            'max_uses' => 1,
            'used_count' => 0,
            'expires_at' => now()->addMonths(6),
            'is_active' => true,
        ]);

        // Guest access is unauthorized
        $this->getJson('/api/v1/me/store-credits')->assertUnauthorized();

        // Staff token is forbidden (customer ability required)
        $this->asToken($this->staffToken)->getJson('/api/v1/me/store-credits')->assertForbidden();

        // Check GET /me/store-credits only returns the active valid one
        $res = $this->asToken($this->customerToken)
            ->getJson('/api/v1/me/store-credits')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'CREDIT-TEST-ACTV');
    }
}
