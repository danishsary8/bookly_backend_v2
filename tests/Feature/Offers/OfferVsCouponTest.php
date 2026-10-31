<?php

namespace Tests\Feature\Offers;

use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Series;
use App\Services\Offers\OfferService;
use App\Services\Orders\CheckoutService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Offer vs coupon in the coupon check and the checkout preview (spec 4.4, 6.2, 6.3; examples 1, 2 and 4). */
class OfferVsCouponTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private CustomerAddress $address;

    private Series $holmes;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.free_shipping_threshold' => '50.00']);
        $this->customer = Customer::factory()->create();
        $this->address = CustomerAddress::factory()->create(['customer_id' => $this->customer->id]); // Phnom Penh, $1.50
        $this->withToken($this->customer->createToken('t', ['customer'])->plainTextToken);
        $this->holmes = Series::factory()->create(['name' => 'Sherlock Holmes']);
    }

    private function variant(string $price, ?Series $series = null): BookVariant
    {
        $book = Book::factory()->create(['series_id' => $series?->id]);
        $book->categories()->attach(Category::factory()->create());

        return BookVariant::factory()->create(['book_id' => $book->id, 'price_usd' => $price]);
    }

    /** The owner's cart: Holmes A $10 + Holmes B $10 + cookbook $30, with "Buy 2 Holmes, get 15%" live. */
    private function example1Cart(): Offer
    {
        $offer = Offer::factory()->selected()->create(['name' => 'Buy 2 Holmes', 'min_quantity' => 2, 'percent_off' => '15.00']);
        $offer->targets()->create(['series_id' => $this->holmes->id]);
        app(OfferService::class)->forget();

        foreach ([$this->variant('10.00', $this->holmes), $this->variant('10.00', $this->holmes), $this->variant('30.00')] as $v) {
            $this->postJson('/api/v1/cart/items', ['book_variant_id' => $v->id])->assertCreated();
        }

        return $offer;
    }

    private function preview(?string $code = null)
    {
        return $this->postJson('/api/v1/checkout/preview', array_filter(['coupon_code' => $code, 'address_id' => $this->address->id]));
    }

    private function check(string $code)
    {
        return $this->postJson('/api/v1/cart/coupon/check', ['code' => $code]);
    }

    /** Preview, the shared totals function and a real checkout give the same discount, delivery and total. */
    private function assertPreviewMatchesTotalsAndCheckout(?string $code, string $discount, string $shipping, string $total): void
    {
        $preview = $this->preview($code)->assertOk()->json('data');
        $shared = app(CheckoutService::class)->quote($this->customer->fresh(), $code, $this->address->id);

        $this->assertSame([$discount, $shipping, $total], [$preview['discount']['usd'], $preview['shipping_fee']['usd'], $preview['total']['usd']]);
        $this->assertSame(
            [$shared['discount_cents'], $shared['shipping_cents'], $shared['total_cents']],
            [Money::toCents($preview['discount']['usd']), Money::toCents($preview['shipping_fee']['usd']), Money::toCents($preview['total']['usd'])],
        );

        $this->withHeader('Idempotency-Key', Str::random(20))
            ->postJson('/api/v1/checkout', array_filter(['address_id' => $this->address->id, 'payment_method' => 'cod', 'coupon_code' => $code]))
            ->assertCreated();
        $order = Order::latest('id')->first();
        $this->assertSame([$discount, $shipping, $total], [$order->discount_amount, $order->shipping_fee, $order->total_amount]);
    }

    public function test_example_1_offer_only_preview_equals_totals_and_checkout(): void
    {
        $offer = $this->example1Cart();

        $this->preview()->assertOk()
            ->assertJsonPath('data.subtotal.usd', '50.00')
            ->assertJsonPath('data.discount.usd', '3.00')
            ->assertJsonPath('data.applied', 'offer')
            ->assertJsonPath('data.offer.id', $offer->id)
            ->assertJsonPath('data.offer.discount_usd', '3.00')
            ->assertJsonPath('data.coupon_code', null)
            ->assertJsonPath('data.coupon_not_used', null)
            ->assertJsonPath('data.is_free_shipping', false)          // $47.00 < $50.00
            ->assertJsonPath('data.amount_needed_for_free_shipping.usd', '3.00');

        $this->assertPreviewMatchesTotalsAndCheckout(null, '3.00', '1.50', '48.50');
    }

    /** Example 2: 10% coupon ($5.00) beats the offer ($3.00). */
    public function test_example_2_coupon_wins_preview_equals_totals_and_checkout(): void
    {
        $this->example1Cart();
        $coupon = Coupon::factory()->create(['code' => 'READ10', 'value' => '10.00']);

        $this->check('read10')->assertOk()
            ->assertJsonPath('data.discount_usd', '5.00')                 // the coupon alone, as before
            ->assertJsonPath('data.total_before_shipping_usd', '45.00')
            ->assertJsonPath('data.applied', 'coupon')
            ->assertJsonPath('data.offer.discount_usd', '3.00')           // the offer it beat
            ->assertJsonPath('data.coupon_not_used', null)
            ->assertJsonPath('data.best_discount_usd', '5.00')
            ->assertJsonPath('data.best_total_before_shipping_usd', '45.00')
            ->assertJsonPath('data.free_shipping.amount_needed_usd', '5.00');

        $this->preview('READ10')->assertOk()
            ->assertJsonPath('data.applied', 'coupon')
            ->assertJsonPath('data.coupon_code', 'READ10')
            ->assertJsonPath('data.coupon_not_used', null);

        $this->assertPreviewMatchesTotalsAndCheckout('READ10', '5.00', '1.50', '46.50');
        $order = Order::latest('id')->first();
        $this->assertSame($coupon->id, $order->coupon_id);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    /** Example 4: offer and coupon both $3.00 -> the offer wins; the coupon is not used and can be used later. */
    public function test_example_4_tie_goes_to_the_offer_and_the_coupon_is_kept(): void
    {
        $this->example1Cart();
        $coupon = Coupon::factory()->create(['code' => 'THREE', 'type' => 'fixed', 'value' => '3.00']);

        $this->check('three')->assertOk()
            ->assertJsonPath('data.discount_usd', '3.00')
            ->assertJsonPath('data.applied', 'offer')
            ->assertJsonPath('data.coupon_not_used', ['code' => 'THREE', 'reason' => 'offer_better'])
            ->assertJsonPath('data.best_discount_usd', '3.00');

        $this->preview('THREE')->assertOk()
            ->assertJsonPath('data.discount.usd', '3.00')
            ->assertJsonPath('data.applied', 'offer')
            ->assertJsonPath('data.coupon_code', null)
            ->assertJsonPath('data.coupon_not_used', ['code' => 'THREE', 'reason' => 'offer_better']);

        $this->assertPreviewMatchesTotalsAndCheckout('THREE', '3.00', '1.50', '48.50');
        $this->assertNull(Order::latest('id')->first()->coupon_id);
        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_store_credit_is_compared_like_any_coupon(): void
    {
        $this->example1Cart();
        Coupon::factory()->create(['code' => 'CREDIT-SMALL', 'type' => 'fixed', 'value' => '2.00', 'customer_id' => $this->customer->id]);
        Coupon::factory()->create(['code' => 'CREDIT-BIG', 'type' => 'fixed', 'value' => '8.00', 'customer_id' => $this->customer->id]);

        $this->preview('CREDIT-SMALL')->assertOk()
            ->assertJsonPath('data.applied', 'offer')
            ->assertJsonPath('data.discount.usd', '3.00')
            ->assertJsonPath('data.coupon_not_used.code', 'CREDIT-SMALL');

        $this->preview('CREDIT-BIG')->assertOk()
            ->assertJsonPath('data.applied', 'coupon')
            ->assertJsonPath('data.discount.usd', '8.00')
            ->assertJsonPath('data.coupon_code', 'CREDIT-BIG');
    }

    public function test_bad_coupons_keep_todays_422_even_when_an_offer_exists(): void
    {
        $this->example1Cart();
        Coupon::factory()->create(['code' => 'OLD', 'expires_at' => now()->subDay()]);
        Coupon::factory()->create(['code' => 'BIG', 'min_order_amount' => '100.00']);
        Coupon::factory()->create(['code' => 'OFF', 'is_active' => false]);

        $cases = [
            'NOPE' => 'This coupon code is not valid.',
            'OLD' => 'This coupon has expired.',
            'BIG' => 'Order subtotal must be at least 100.00.',
            'OFF' => 'This coupon is not active.',
        ];
        foreach ($cases as $code => $message) {
            $this->check($code)->assertUnprocessable()->assertJsonPath('errors.code.0', $message);
            $this->preview($code)->assertUnprocessable()->assertJsonPath('errors.coupon_code.0', $message);
        }
    }

    public function test_five_unknown_codes_still_lock_the_check_when_an_offer_exists(): void
    {
        $this->example1Cart();

        foreach (range(1, 5) as $i) {
            $this->check("WRONG{$i}")->assertUnprocessable();
        }
        $this->check('WRONG6')->assertStatus(429)->assertHeader('Retry-After', '900');
    }

    public function test_without_any_offer_old_responses_are_unchanged_plus_null_fields(): void
    {
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $this->variant('20.00')->id])->assertCreated();
        Coupon::factory()->create(['code' => 'FIVE', 'type' => 'fixed', 'value' => '5']);

        $this->preview('five')->assertOk()
            ->assertJsonPath('data.discount.usd', '5.00')
            ->assertJsonPath('data.total.usd', '16.50')
            ->assertJsonPath('data.coupon_code', 'FIVE')
            ->assertJsonPath('data.applied', 'coupon')
            ->assertJsonPath('data.offer', null)
            ->assertJsonPath('data.coupon_not_used', null);

        $this->preview()->assertOk()
            ->assertJsonPath('data.discount.usd', '0.00')
            ->assertJsonPath('data.applied', null)
            ->assertJsonPath('data.offer', null);
    }
}
