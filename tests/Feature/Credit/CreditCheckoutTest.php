<?php

namespace Tests\Feature\Credit;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\StaffUser;
use App\Services\Credit\CreditWallet;
use App\Services\Orders\OrderStatusService;
use App\Services\Returns\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class CreditCheckoutTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    private Customer $customer;

    private CustomerAddress $address;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['shop.gift_cards_enabled' => true]);
        $this->customer = Customer::factory()->create();
        $this->address = CustomerAddress::factory()->for($this->customer)->create();
        $this->withToken($this->customer->createToken('t', ['customer'])->plainTextToken);
    }

    private function cart(): void
    {
        $variant = BookVariant::factory()->format(BookFormat::Ebook)->create(['price_usd' => '10.00']);
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => 1])->assertCreated();
    }

    private function checkout(string $method = 'cod')
    {
        $due = $this->postJson('/api/v1/checkout/preview', ['use_credit' => true])->json('data.payment_due.usd');

        return $this->withHeader('Idempotency-Key', 'wallet-checkout-1')->postJson('/api/v1/checkout', [
            'address_id' => $this->address->id, 'payment_method' => $method, 'use_credit' => true, 'expected_payment_due_usd' => $due,
        ]);
    }

    public function test_partial_credit_reduces_collection_without_changing_order_value_and_cancel_restores_it(): void
    {
        app(CreditWallet::class)->move($this->customer, 300, 'gift-test');
        $this->cart();
        $this->postJson('/api/v1/checkout/preview', ['use_credit' => true])->assertOk()
            ->assertJsonPath('data.total.usd', '10.00')->assertJsonPath('data.payment_due.usd', '7.00');
        $id = $this->checkout()->assertCreated()->assertJsonPath('data.credit_used_usd', '3.00')
            ->assertJsonPath('data.payment_due_usd', '7.00')->json('data.id');
        $this->checkout()->assertOk();
        $this->assertDatabaseHas('payments', ['order_id' => $id, 'amount' => '7.00']);
        $this->assertSame(0, app(CreditWallet::class)->balance($this->customer));
        app(OrderStatusService::class)->cancelByCustomer(Order::findOrFail($id));
        $this->assertSame(300, app(CreditWallet::class)->balance($this->customer));
    }

    public function test_full_credit_keeps_unused_balance_and_needs_no_fake_external_payment(): void
    {
        app(CreditWallet::class)->move($this->customer, 2000, 'gift-test');
        $this->cart();
        $this->checkout('khqr')->assertCreated()->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_status', 'succeeded')->assertJsonPath('data.payment_due_usd', '0.00');
        $this->assertSame(1000, app(CreditWallet::class)->balance($this->customer));
        $this->assertDatabaseCount('payments', 0);
        app(OrderStatusService::class)->cancelByCustomer(Order::sole());
        $this->assertSame(2000, app(CreditWallet::class)->balance($this->customer));
    }

    public function test_changed_balance_rejects_the_order_instead_of_silently_increasing_external_payment(): void
    {
        app(CreditWallet::class)->move($this->customer, 300, 'gift-test');
        $this->cart();
        app(CreditWallet::class)->move($this->customer, -300, 'another-tab');
        $this->withHeader('Idempotency-Key', 'wallet-changed-1')->postJson('/api/v1/checkout', [
            'address_id' => $this->address->id, 'payment_method' => 'cod', 'use_credit' => true, 'expected_payment_due_usd' => '7.00',
        ])->assertUnprocessable()->assertJsonValidationErrors('use_credit');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_disabled_flags_do_not_spend_a_balance(): void
    {
        app(CreditWallet::class)->move($this->customer, 2000, 'gift-test');
        config(['shop.gift_cards_enabled' => false, 'shop.referrals_enabled' => false]);
        $this->cart();
        $this->checkout()->assertCreated()->assertJsonPath('data.credit_used_usd', '0.00');
        $this->assertSame(2000, app(CreditWallet::class)->balance($this->customer));
    }

    public function test_partial_returns_restore_credit_share_and_only_cash_share_becomes_a_coupon(): void
    {
        $order = $this->deliveredOrder($this->customer, [[$this->physical('10.00'), 2]]);
        $order->update(['credit_used_cents' => 1000]);
        $order->payments()->update(['amount' => '10.00']);
        $staff = StaffUser::factory()->create();
        foreach ([1, 2] as $part) {
            $return = OrderReturn::create(['order_id' => $order->id, 'customer_id' => $this->customer->id, 'reason' => 'Damaged', 'status' => ReturnStatus::Approved, 'requested_at' => now()]);
            $return->items()->create(['order_item_id' => $order->items->first()->id, 'quantity' => 1]);
            $refunded = app(ReturnService::class)->refund($return, $staff, 'store_credit');
            $this->assertSame(500, $refunded->credit_refund_cents);
            $this->assertSame('5.00', $refunded->storeCreditCoupon->value);
            $this->assertSame($part * 500, app(CreditWallet::class)->balance($this->customer));
        }
        $this->assertSame(OrderStatus::Returned, $order->fresh()->status);
    }
}
