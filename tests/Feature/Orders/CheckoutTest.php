<?php

namespace Tests\Feature\Orders;

use App\Enums\BookFormat;
use App\Models\BookVariant;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\StaffUser;
use App\Notifications\LowStockNotification;
use App\Notifications\OrderPlacedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

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

    private function checkout(array $body = [], string $key = 'checkout-key-0001')
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', [
            'address_id' => $this->address->id, 'payment_method' => 'cod', ...$body,
        ]);
    }

    public function test_places_a_cod_order_with_shipping_coupon_stock_and_history(): void
    {
        $paperback = BookVariant::factory()->create(['price_usd' => '15.00', 'stock_quantity' => 20]);
        $ebook = BookVariant::factory()->format(BookFormat::Ebook)->create(['price_usd' => '5.00', 'stock_quantity' => 0]);
        Coupon::factory()->create(['code' => 'TENOFF', 'value' => '10']);
        $this->addToCart($paperback, 2);
        $this->addToCart($ebook);

        $response = $this->checkout(['coupon_code' => 'tenoff'])->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_method', 'cod')
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.subtotal_usd', '35.00')
            ->assertJsonPath('data.discount_usd', '3.50')
            ->assertJsonPath('data.shipping_fee_usd', '1.50')
            ->assertJsonPath('data.tax_usd', '0.00')
            ->assertJsonPath('data.total_usd', '33.00')
            ->assertJsonPath('data.coupon_code', 'TENOFF')
            ->assertJsonPath('data.shipping_address.city', 'Phnom Penh')
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.status_history.0.status', 'pending');

        $this->assertMatchesRegularExpression('/^ORD-\d{8}-[A-Z2-9]{5}$/', $response->json('data.order_number'));
        $order = Order::sole();

        $this->assertSame(18, $paperback->fresh()->stock_quantity);
        $this->assertSame(0, $ebook->fresh()->stock_quantity, 'digital formats have no stock to deduct');
        $this->assertDatabaseHas('inventory_movements', ['book_variant_id' => $paperback->id, 'change_qty' => -2, 'reason' => 'sale', 'reference_type' => 'order', 'reference_id' => $order->id]);
        $this->assertSame(1, InventoryMovement::count());
        $this->assertSame(1, Coupon::firstWhere('code', 'TENOFF')->used_count);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'cod', 'status' => 'pending', 'amount' => '33.00']);

        $this->getJson('/api/v1/cart')->assertJsonCount(0, 'data.items');
        Notification::assertSentTo($this->customer, OrderPlacedNotification::class, fn ($n) => $n->order->is($order));
    }

    public function test_digital_only_order_has_no_shipping_fee(): void
    {
        $this->addToCart(BookVariant::factory()->format(BookFormat::Audiobook)->create(['price_usd' => '9.00']));

        $this->checkout()->assertCreated()->assertJsonPath('data.shipping_fee_usd', '0.00')->assertJsonPath('data.total_usd', '9.00');
    }

    public function test_checkout_uses_todays_price(): void
    {
        $variant = BookVariant::factory()->create(['price_usd' => '10.00']);
        $this->addToCart($variant);
        $variant->update(['price_usd' => '12.00']);

        $this->checkout()->assertCreated()->assertJsonPath('data.items.0.unit_price_usd', '12.00')->assertJsonPath('data.subtotal_usd', '12.00');
    }

    public function test_same_idempotency_key_returns_the_same_order_once(): void
    {
        $variant = BookVariant::factory()->create(['stock_quantity' => 10]);
        $this->addToCart($variant, 3);

        $first = $this->checkout(key: 'double-click-1')->assertCreated()->json('data.id');
        $this->checkout(key: 'double-click-1')->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first);

        $this->assertSame(1, Order::count());
        $this->assertSame(7, $variant->fresh()->stock_quantity);
        Notification::assertSentToTimes($this->customer, OrderPlacedNotification::class, 1);
    }

    public function test_idempotency_key_header_is_required(): void
    {
        $this->addToCart(BookVariant::factory()->create());

        $this->postJson('/api/v1/checkout', ['address_id' => $this->address->id, 'payment_method' => 'cod'])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->checkout(key: 'short')->assertUnprocessable();
    }

    public function test_blocked_cart_creates_nothing_and_keeps_the_cart(): void
    {
        $variant = BookVariant::factory()->create(['stock_quantity' => 5]);
        $this->addToCart($variant, 3);
        $variant->update(['stock_quantity' => 2]);

        $this->checkout()->assertUnprocessable()->assertJsonValidationErrors('cart');

        $this->assertSame(0, Order::count());
        $this->assertSame(2, $variant->fresh()->stock_quantity);
        $this->getJson('/api/v1/cart')->assertJsonCount(1, 'data.items');
    }

    public function test_rejections(): void
    {
        $this->checkout()->assertUnprocessable()->assertJsonPath('errors.cart.0', 'Your cart is empty.');

        $this->addToCart(BookVariant::factory()->create());
        $foreign = CustomerAddress::factory()->create();

        $this->checkout(['address_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('address_id');
        $this->checkout(['payment_method' => 'card'])->assertUnprocessable()
            ->assertJsonPath('errors.payment_method.0', 'Only cash on delivery is available right now.');
        $this->checkout(['coupon_code' => 'NOPE'])->assertUnprocessable()
            ->assertJsonPath('errors.coupon_code.0', 'This coupon code is not valid.');

        $this->assertSame(0, Order::count());
    }

    public function test_crossing_the_low_stock_threshold_alerts_all_staff(): void
    {
        $staff = StaffUser::factory()->count(2)->create();
        $variant = BookVariant::factory()->create(['stock_quantity' => 6, 'low_stock_threshold' => 5]);
        $this->addToCart($variant, 2);

        $this->checkout()->assertCreated();

        Notification::assertSentTo($staff, LowStockNotification::class, fn ($n) => $n->variant['stock_quantity'] === 4);
    }

    public function test_preview_shows_totals_without_saving(): void
    {
        $this->addToCart(BookVariant::factory()->create(['price_usd' => '20.00']));
        Coupon::factory()->create(['code' => 'FIVE', 'type' => 'fixed', 'value' => '5']);

        $this->postJson('/api/v1/checkout/preview', ['coupon_code' => 'five', 'address_id' => $this->address->id])->assertOk()
            ->assertJsonPath('data.subtotal.usd', '20.00')
            ->assertJsonPath('data.discount.usd', '5.00')
            ->assertJsonPath('data.shipping_fee.usd', '1.50')
            ->assertJsonPath('data.delivery_area', 'phnom_penh')
            ->assertJsonPath('data.total.usd', '16.50')
            ->assertJsonPath('data.requires_shipping', true)
            ->assertJsonPath('data.can_checkout', true);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Coupon::firstWhere('code', 'FIVE')->used_count);
    }

    public function test_delivery_outside_phnom_penh_costs_the_provinces_fee(): void
    {
        $this->addToCart(BookVariant::factory()->create(['price_usd' => '10.00']));
        $this->address->update(['city' => 'Siem Reap', 'state' => null]);

        $this->checkout()->assertCreated()->assertJsonPath('data.shipping_fee_usd', '3.00')->assertJsonPath('data.total_usd', '13.00');
    }

    public function test_phnom_penh_is_recognised_however_it_is_written(): void
    {
        $this->addToCart(BookVariant::factory()->create(['price_usd' => '10.00']));

        foreach (['phnom-penh', ' PHNOMPENH ', 'ភ្នំពេញ'] as $city) {
            $this->address->update(['city' => $city]);
            $this->postJson('/api/v1/checkout/preview', ['address_id' => $this->address->id])
                ->assertOk()->assertJsonPath('data.shipping_fee.usd', '1.50');
        }

        $this->address->update(['city' => 'Chbar Ampov', 'state' => 'Phnom Penh']);
        $this->postJson('/api/v1/checkout/preview', ['address_id' => $this->address->id])->assertJsonPath('data.delivery_area', 'phnom_penh');
    }

    public function test_preview_uses_the_default_address_or_quotes_the_provinces_fee_without_one(): void
    {
        $this->addToCart(BookVariant::factory()->create(['price_usd' => '10.00']));

        $this->address->update(['is_default' => true]);
        $this->postJson('/api/v1/checkout/preview')->assertJsonPath('data.delivery_area', 'phnom_penh')->assertJsonPath('data.shipping_fee.usd', '1.50');

        $this->address->delete();
        $this->postJson('/api/v1/checkout/preview')->assertJsonPath('data.delivery_area', null)->assertJsonPath('data.shipping_fee.usd', '3.00');
    }

    public function test_preview_rejects_someone_elses_address(): void
    {
        $other = CustomerAddress::factory()->for(Customer::factory())->create();
        $this->addToCart(BookVariant::factory()->create());

        $this->postJson('/api/v1/checkout/preview', ['address_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('address_id');
    }

    public function test_unverified_customers_cannot_check_out(): void
    {
        $token = Customer::factory()->unverified()->create()->createToken('t', ['customer'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->withHeader('Idempotency-Key', 'abcdefgh1')->postJson('/api/v1/checkout', [])->assertForbidden();
    }
}
