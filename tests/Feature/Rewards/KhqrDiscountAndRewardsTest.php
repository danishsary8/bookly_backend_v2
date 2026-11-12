<?php

namespace Tests\Feature\Rewards;

use App\Enums\OrderStatus;
use App\Models\AdminAuditLog;
use App\Models\BookVariant;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\RewardRule;
use App\Models\StaffUser;
use App\Notifications\RewardCouponNotification;
use App\Services\Orders\OrderStatusService;
use App\Services\Settings\ShopSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/** KHQR discount, cash on delivery limit and "next order" reward coupons (owner 2026-11-12). */
class KhqrDiscountAndRewardsTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private Customer $customer;

    private CustomerAddress $address;

    private BookVariant $book;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['shop.shipping_fees' => ['phnom_penh' => '1.50', 'provinces' => '3.00'], 'shop.free_shipping_threshold' => '50.00']);
        $this->customer = Customer::factory()->create();
        $this->address = CustomerAddress::factory()->for($this->customer)->create(['city' => 'Phnom Penh']);
        $this->book = BookVariant::factory()->create(['price_usd' => '10.00', 'stock_quantity' => 100]);
        $this->asCustomer();
    }

    private function asCustomer(): static
    {
        return $this->asToken($this->customer->createToken('t', ['customer'])->plainTextToken);
    }

    private function settings(?string $khqr, ?string $codMax): void
    {
        app(ShopSettings::class)->set(['khqr_discount_percent' => $khqr, 'cod_max_order' => $codMax]);
    }

    private function cart(int $quantity, ?BookVariant $variant = null): void
    {
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => ($variant ?? $this->book)->id, 'quantity' => $quantity])->assertCreated();
    }

    private function checkout(string $method, array $body = [])
    {
        return $this->withHeader('Idempotency-Key', 'key-'.uniqid())->postJson('/api/v1/checkout', [
            'address_id' => $this->address->id, 'payment_method' => $method, ...$body,
        ]);
    }

    private function deliver(Order $order): void
    {
        $staff = StaffUser::factory()->withTwoFactor()->admin()->create();
        $status = app(OrderStatusService::class);
        foreach ([OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered] as $to) {
            $status->changeByStaff($order->fresh(), $to, $staff);
        }
    }

    public function test_khqr_takes_its_percent_off_the_books_and_the_order_keeps_it(): void
    {
        $this->settings('3.00', null);
        $this->cart(2);

        $this->postJson('/api/v1/checkout/preview', ['address_id' => $this->address->id, 'payment_method' => 'cod'])->assertOk()
            ->assertJsonPath('data.discount.usd', '0.00')
            ->assertJsonPath('data.khqr_saving.usd', '0.60')
            ->assertJsonPath('data.khqr_discount_percent', '3.00')
            ->assertJsonPath('data.total.usd', '21.50');
        $this->postJson('/api/v1/checkout/preview', ['address_id' => $this->address->id, 'payment_method' => 'khqr'])->assertOk()
            ->assertJsonPath('data.khqr_discount.usd', '0.60')
            ->assertJsonPath('data.discount.usd', '0.60')
            ->assertJsonPath('data.total.usd', '20.90');
        $this->getJson('/api/v1/cart')->assertJsonPath('data.khqr_discount_percent', '3.00');

        $this->checkout('khqr')->assertCreated()
            ->assertJsonPath('data.khqr_discount_usd', '0.60')
            ->assertJsonPath('data.discount_usd', '0.60')
            ->assertJsonPath('data.total_usd', '20.90');
        $this->assertSame('20.90', Order::first()->payments()->first()->amount);
    }

    public function test_khqr_discount_comes_after_a_coupon_and_never_costs_free_delivery(): void
    {
        $this->settings('3.00', null);
        Coupon::factory()->create(['code' => 'TEN', 'value' => '10.00']);
        $this->cart(6); // $60, coupon -> $54, still over the $50 free delivery

        $this->postJson('/api/v1/checkout/preview', ['address_id' => $this->address->id, 'coupon_code' => 'TEN', 'payment_method' => 'khqr'])->assertOk()
            ->assertJsonPath('data.is_free_shipping', true)
            ->assertJsonPath('data.khqr_discount.usd', '1.62')
            ->assertJsonPath('data.discount.usd', '7.62')
            ->assertJsonPath('data.total.usd', '52.38');
    }

    public function test_cash_on_delivery_stops_at_the_limit_and_khqr_still_works(): void
    {
        $this->settings(null, '80.00');
        $this->cart(9); // $90 + free delivery

        $this->postJson('/api/v1/checkout/preview', ['address_id' => $this->address->id])->assertOk()
            ->assertJsonPath('data.cod_allowed', false)
            ->assertJsonPath('data.cod_max.usd', '80.00');
        $this->checkout('cod')->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->checkout('khqr')->assertCreated();
    }

    public function test_two_books_delivered_earn_a_capped_coupon_for_the_next_order_only(): void
    {
        $this->cart(2);
        $order = Order::findOrFail($this->checkout('cod')->assertCreated()->json('data.id'));
        $this->assertSame(0, Coupon::whereNotNull('earned_by_order_id')->count(), 'nothing before delivery');

        $this->deliver($order);
        $coupon = Coupon::where('earned_by_order_id', $order->id)->firstOrFail();
        $this->assertSame($this->customer->id, $coupon->customer_id);
        $this->assertSame(['10.00', '5.00', 1], [$coupon->value, $coupon->max_discount, $coupon->max_uses]);
        $this->assertTrue($coupon->expires_at->between(now()->addDays(29), now()->addDays(31)));
        Notification::assertSentTo($this->customer, RewardCouponNotification::class);

        $this->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('data.reward_coupon.code', $coupon->code)->assertJsonPath('data.reward_coupon.is_usable', true);
        $this->getJson('/api/v1/me/coupons')->assertOk()->assertJsonPath('data.0.code', $coupon->code)->assertJsonPath('data.0.is_usable', true);
        $this->getJson('/api/v1/me/store-credits')->assertOk()->assertJsonCount(0, 'data');

        // Next order: 10% of $80 = $8, capped at $5. It earns no new reward when delivered.
        $this->cart(8);
        $next = Order::findOrFail($this->checkout('cod', ['coupon_code' => $coupon->code])->assertCreated()->assertJsonPath('data.discount_usd', '5.00')->json('data.id'));
        $this->deliver($next);
        $this->assertNull(Coupon::where('earned_by_order_id', $next->id)->first());

        // One use only, and only for this customer.
        $this->cart(2);
        $this->postJson('/api/v1/cart/coupon/check', ['code' => $coupon->code])->assertUnprocessable();
        $other = Customer::factory()->create();
        $this->asToken($other->createToken('t', ['customer'])->plainTextToken);
        $this->cart(2);
        $this->postJson('/api/v1/cart/coupon/check', ['code' => $coupon->code])->assertUnprocessable();
    }

    public function test_one_book_earns_nothing_and_the_cart_says_how_many_more(): void
    {
        $this->cart(1);
        $this->getJson('/api/v1/cart')->assertJsonPath('data.reward.add_books', 1)->assertJsonPath('data.reward.earns', false)
            ->assertJsonPath('data.reward.percent_off', '10.00')->assertJsonPath('data.reward.max_discount_usd', '5.00');
        $order = Order::findOrFail($this->checkout('cod')->assertCreated()->json('data.id'));
        $this->deliver($order);
        $this->assertSame(0, Coupon::whereNotNull('earned_by_order_id')->count());

        RewardRule::query()->update(['is_active' => false]);
        $this->cart(1);
        $this->getJson('/api/v1/cart')->assertJsonPath('data.reward', null);
    }

    public function test_khqr_payment_earns_the_reward_and_cancelling_switches_it_off(): void
    {
        $this->cart(2);
        $id = $this->checkout('khqr')->assertCreated()->json('data.id');
        $this->postJson("/api/v1/orders/{$id}/payment/simulate")->assertOk();
        $coupon = Coupon::where('earned_by_order_id', $id)->firstOrFail();
        $this->assertTrue($coupon->is_active);

        $staff = StaffUser::factory()->withTwoFactor()->admin()->create();
        app(OrderStatusService::class)->changeByStaff(Order::findOrFail($id), OrderStatus::Cancelled, $staff);
        $this->assertFalse($coupon->fresh()->is_active);
        $this->getJson('/api/v1/me/coupons')->assertJsonPath('data.0.is_usable', false);
    }

    public function test_ending_reminder_goes_once(): void
    {
        $this->cart(2);
        $order = Order::findOrFail($this->checkout('cod')->assertCreated()->json('data.id'));
        $this->deliver($order);
        Coupon::where('earned_by_order_id', $order->id)->update(['expires_at' => now()->addDays(2)]);

        $this->artisan('rewards:remind')->assertSuccessful();
        $this->artisan('rewards:remind')->assertSuccessful();
        Notification::assertSentToTimes($this->customer, RewardCouponNotification::class, 2); // earned + one reminder
    }

    public function test_staff_see_rules_and_settings_and_only_admins_change_them(): void
    {
        $staff = $this->staffToken();
        $this->asToken($staff)->getJson('/api/v1/staff/reward-rules')->assertOk()->assertJsonPath('data.0.min_books', 2)->assertJsonPath('data.0.issued_count', 0);
        $this->asToken($staff)->getJson('/api/v1/staff/settings/payments')->assertOk()->assertJsonPath('data.khqr_discount_percent', null);
        $this->asToken($staff)->postJson('/api/v1/staff/reward-rules', ['name' => 'X', 'min_books' => 3, 'percent_off' => '5', 'valid_days' => 10])->assertForbidden();
        $this->asToken($staff)->patchJson('/api/v1/staff/settings/payments', ['khqr_discount_percent' => '3'])->assertForbidden();

        $admin = $this->staffToken(admin: true);
        $this->asToken($admin)->postJson('/api/v1/staff/reward-rules', ['name' => 'No rule', 'percent_off' => '5', 'valid_days' => 10])->assertUnprocessable();
        $id = $this->asToken($admin)->postJson('/api/v1/staff/reward-rules', ['name' => 'Spend $40', 'is_active' => true, 'min_amount' => '40', 'percent_off' => '5', 'valid_days' => 14])
            ->assertCreated()->assertJsonPath('data.min_amount_usd', '40.00')->json('data.id');
        $this->asToken($admin)->patchJson("/api/v1/staff/reward-rules/{$id}", ['percent_off' => '7.5'])->assertOk()->assertJsonPath('data.percent_off', '7.50');
        $this->asToken($admin)->patchJson('/api/v1/staff/settings/payments', ['khqr_discount_percent' => '2.5', 'cod_max_order_usd' => '100'])->assertOk()
            ->assertJsonPath('data.khqr_discount_percent', '2.50')->assertJsonPath('data.cod_max_order_usd', '100.00');
        $this->assertSame(250, app(ShopSettings::class)->khqrDiscountBasisPoints());
        $this->asToken($admin)->deleteJson("/api/v1/staff/reward-rules/{$id}")->assertNoContent();
        $this->assertTrue(AdminAuditLog::where('action', 'shop_settings.updated')->exists());
        $this->assertTrue(AdminAuditLog::where('action', 'reward_rule.created')->exists());
    }
}
