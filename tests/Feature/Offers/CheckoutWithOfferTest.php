<?php

namespace Tests\Feature\Offers;

use App\Enums\OrderStatus;
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
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * POST /checkout saves the winner (spec 4.4, 4.7, 4.9, O1 = A), cancel gives the use back, refunds use each line's own
 * discount (4.8). Examples 1 and 2 from the spec. Every placed order is checked: the lines add up to the order discount.
 */
class CheckoutWithOfferTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    private Customer $customer;

    private string $token;

    private CustomerAddress $address;

    private Series $holmes;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.free_shipping_threshold' => '50.00']);
        $this->holmes = Series::factory()->create(['name' => 'Sherlock Holmes']);
        [$this->customer, $this->token, $this->address] = $this->shopper();
    }

    /** @return array{Customer, string, CustomerAddress} */
    private function shopper(): array
    {
        $customer = Customer::factory()->create();

        return [
            $customer,
            $customer->createToken('t', ['customer'])->plainTextToken,
            CustomerAddress::factory()->create(['customer_id' => $customer->id]), // Phnom Penh: $1.50
        ];
    }

    private function variant(string $price, ?Series $series = null, int $stock = 50): BookVariant
    {
        $book = Book::factory()->create(['series_id' => $series?->id]);
        $book->categories()->attach(Category::factory()->create());

        return BookVariant::factory()->create(['book_id' => $book->id, 'price_usd' => $price, 'stock_quantity' => $stock]);
    }

    private function holmesOffer(array $attributes = []): Offer
    {
        $offer = Offer::factory()->selected()->create(['name' => 'Buy 2 Holmes', 'min_quantity' => 2, 'percent_off' => '15.00', ...$attributes]);
        $offer->targets()->create(['series_id' => $this->holmes->id]);
        app(OfferService::class)->forget();

        return $offer;
    }

    /** @return array{BookVariant, BookVariant, BookVariant} Holmes A $10, Holmes B $10, cookbook $30 */
    private function example1Books(): array
    {
        return [$this->variant('10.00', $this->holmes), $this->variant('10.00', $this->holmes), $this->variant('30.00')];
    }

    private function fillCart(array $variants, ?string $token = null): void
    {
        foreach ($variants as $variant) {
            $this->asToken($token ?? $this->token)->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id])->assertCreated();
        }
    }

    private function checkout(array $body = [], ?string $key = null, ?string $token = null, ?CustomerAddress $address = null): TestResponse
    {
        return $this->asToken($token ?? $this->token)
            ->withHeader('Idempotency-Key', $key ?? Str::random(24))
            ->postJson('/api/v1/checkout', ['address_id' => ($address ?? $this->address)->id, 'payment_method' => 'cod', ...$body]);
    }

    /** Owner's rule: the saved line discounts always add up exactly to orders.discount_amount. */
    private function assertLinesAddUp(Order $order): void
    {
        $order->load('items');
        $this->assertSame(
            Money::toCents($order->discount_amount),
            $order->items->sum(fn ($i) => Money::toCents($i->discount_amount)),
            "order {$order->id}: line discounts must add up to the order discount",
        );
        foreach ($order->items as $item) {
            $this->assertLessThanOrEqual(Money::toCents($item->subtotal), Money::toCents($item->discount_amount));
        }
    }

    private function deliver(Order $order): void
    {
        $order->update(['status' => OrderStatus::Delivered]);
        $order->statusHistory()->create(['status' => OrderStatus::Delivered, 'note' => 'Delivered']);
    }

    /** Customer asks to return one copy of a line, staff approve and refund it; returns the refund amount. */
    private function returnAndRefund(Order $order, BookVariant $variant, string $staffToken): string
    {
        $itemId = $order->items()->where('book_variant_id', $variant->id)->value('id');
        $returnId = $this->asToken($this->token)
            ->postJson("/api/v1/orders/{$order->id}/returns", ['reason' => 'Changed my mind', 'items' => [['order_item_id' => $itemId, 'quantity' => 1]]])
            ->assertCreated()->json('data.id');
        $this->asToken($staffToken)->postJson("/api/v1/staff/returns/{$returnId}/approve")->assertOk();

        return $this->asToken($staffToken)->postJson("/api/v1/staff/returns/{$returnId}/refund", ['method' => 'cash'])
            ->assertOk()->json('data.refund_amount_usd');
    }

    /** Example 1: the offer is saved with its copy, the line discounts, the use; refunds give back each line's own share. */
    public function test_example_1_saves_the_offer_and_refunds_use_each_lines_own_discount(): void
    {
        $offer = $this->holmesOffer();
        [$holmesA, $holmesB, $cookbook] = $this->example1Books();
        $this->fillCart([$holmesA, $holmesB, $cookbook]);

        $response = $this->checkout(['offer_id' => $offer->id])->assertCreated()
            ->assertJsonPath('data.discount_usd', '3.00')
            ->assertJsonPath('data.shipping_fee_usd', '1.50')
            ->assertJsonPath('data.total_usd', '48.50')
            ->assertJsonPath('data.offer', ['id' => $offer->id, 'name' => 'Buy 2 Holmes', 'min_quantity' => 2, 'percent_off' => '15.00'])
            ->assertJsonPath('data.coupon_code', null);
        $this->assertSame(['1.50', '1.50', '0.00'], array_column($response->json('data.items'), 'discount_usd'));

        $order = Order::firstOrFail();
        $this->assertLinesAddUp($order);
        $this->assertSame([$offer->id, 'Buy 2 Holmes', 2, '15.00'], [$order->offer_id, $order->offer_name, $order->offer_min_quantity, $order->offer_percent_off]);
        $this->assertNull($order->coupon_id);
        $this->assertSame(1, $offer->fresh()->used_count);
        $this->assertSame([49, 49, 49], [$holmesA->fresh()->stock_quantity, $holmesB->fresh()->stock_quantity, $cookbook->fresh()->stock_quantity]);

        // Returns: the cookbook had no discount -> full $30.00; Holmes A keeps nothing of the $1.50 -> $8.50.
        // No claw-back: Holmes B, now alone under "buy 2", keeps its own discount.
        $this->deliver($order);
        $staff = $this->staffToken();
        $this->assertSame('30.00', $this->returnAndRefund($order, $cookbook, $staff));
        $this->assertSame('8.50', $this->returnAndRefund($order, $holmesA, $staff));
    }

    /** Example 2: the 10% coupon wins; its $5.00 is split by line price (the last line takes the rounding rest). */
    public function test_example_2_coupon_split_and_refund(): void
    {
        $offer = $this->holmesOffer();
        $coupon = Coupon::factory()->create(['code' => 'READ10', 'value' => '10.00']);
        [$holmesA, $holmesB, $cookbook] = $this->example1Books();
        $this->fillCart([$holmesA, $holmesB, $cookbook]);

        $response = $this->checkout(['coupon_code' => 'READ10'])->assertCreated()
            ->assertJsonPath('data.discount_usd', '5.00')
            ->assertJsonPath('data.total_usd', '46.50')
            ->assertJsonPath('data.offer', null);
        $this->assertSame(['1.00', '1.00', '3.00'], array_column($response->json('data.items'), 'discount_usd'));

        $order = Order::firstOrFail();
        $this->assertLinesAddUp($order);
        $this->assertSame($coupon->id, $order->coupon_id);
        $this->assertNull($order->offer_id);
        $this->assertSame(0, $offer->fresh()->used_count, 'the offer that lost is not counted');
        $this->assertSame(1, $coupon->fresh()->used_count);

        $this->deliver($order);
        $this->assertSame('27.00', $this->returnAndRefund($order, $cookbook, $this->staffToken()));
    }

    public function test_coupon_split_rounding_rest_goes_to_the_last_line_and_adds_up(): void
    {
        // $10 + $10 + $10 with $10.00 off: 333 + 333 + 334.
        Coupon::factory()->create(['code' => 'TEN', 'type' => 'fixed', 'value' => '10.00']);
        $this->fillCart([$this->variant('10.00'), $this->variant('10.00'), $this->variant('10.00')]);

        $response = $this->checkout(['coupon_code' => 'TEN'])->assertCreated();

        $this->assertSame(['3.33', '3.33', '3.34'], array_column($response->json('data.items'), 'discount_usd'));
        $this->assertLinesAddUp(Order::firstOrFail());
    }

    public function test_an_order_without_any_discount_saves_zero_lines(): void
    {
        $this->fillCart([$this->variant('12.00'), $this->variant('8.00')]);

        $response = $this->checkout()->assertCreated()->assertJsonPath('data.discount_usd', '0.00')->assertJsonPath('data.offer', null);

        $this->assertSame(['0.00', '0.00'], array_column($response->json('data.items'), 'discount_usd'));
        $this->assertLinesAddUp(Order::firstOrFail());
    }

    /** An older order (all line discounts 0, a whole-order coupon discount) keeps the proportional refund rule. */
    public function test_old_orders_keep_the_proportional_refund_rule(): void
    {
        $small = $this->physical('10.00');
        $big = $this->physical('30.00');
        $order = $this->deliveredOrder($this->customer, [[$small, 1], [$big, 1]], discount: '4.00');
        $this->assertSame(0, $order->items->sum(fn ($i) => Money::toCents($i->discount_amount)));

        // $30 of $40 returned -> a quarter... three quarters of the $4.00 comes off: 30.00 - 3.00.
        $this->assertSame('27.00', $this->returnAndRefund($order, $big, $this->staffToken()));
    }

    public function test_a_retried_checkout_returns_the_same_order_and_counts_the_offer_once(): void
    {
        $offer = $this->holmesOffer();
        $this->fillCart($this->example1Books());

        $first = $this->checkout(['offer_id' => $offer->id], key: 'same-key-123456')->assertCreated()->json('data.id');
        $this->checkout(['offer_id' => $offer->id], key: 'same-key-123456')
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first);

        $this->assertSame(1, Order::count());
        $this->assertSame(1, $offer->fresh()->used_count);
        $this->assertLinesAddUp(Order::firstOrFail());
    }

    /**
     * The last use, taken between this customer's preview and their "Place order". The other checkout has already
     * committed, so this is the path a waiting checkout takes after the row lock is released. The cache still says the
     * offer is free (it was read before), so this also proves checkout reads fresh under the lock.
     */
    public function test_last_use_taken_first_then_422_with_offer_id_or_full_price_without(): void
    {
        $offer = $this->holmesOffer(['max_orders' => 1]);
        [$other, $otherToken, $otherAddress] = $this->shopper();
        $this->fillCart($this->example1Books());
        $this->fillCart($this->example1Books(), $otherToken);

        // Both see the offer (and the cache now holds used_count 0).
        $this->asToken($this->token)->getJson('/api/v1/cart')->assertJsonPath('data.pricing.offer.id', $offer->id);

        $this->checkout(['offer_id' => $offer->id], token: $otherToken, address: $otherAddress)->assertCreated();
        $this->assertSame(1, $offer->fresh()->used_count);

        // With offer_id: refused, nothing placed, cart kept.
        $this->checkout(['offer_id' => $offer->id])->assertUnprocessable()
            ->assertJsonPath('errors.offer_id.0', __('messages.offer_ended'));
        $this->assertSame(1, Order::count());
        $this->asToken($this->token)->getJson('/api/v1/cart')->assertJsonCount(3, 'data.items');

        // Without offer_id (today's frontend): placed at full price, no offer saved, the limit is not passed.
        // $50.00 without a discount reaches the free-delivery threshold, so delivery is free: $50.00 in total.
        $this->checkout()->assertCreated()
            ->assertJsonPath('data.discount_usd', '0.00')
            ->assertJsonPath('data.shipping_fee_usd', '0.00')
            ->assertJsonPath('data.total_usd', '50.00')
            ->assertJsonPath('data.offer', null);
        $this->assertSame(1, $offer->fresh()->used_count);
        $this->assertLinesAddUp(Order::latest('id')->firstOrFail());
    }

    public function test_when_the_best_offer_is_used_up_under_the_lock_the_next_best_applies(): void
    {
        $best = $this->holmesOffer(['percent_off' => '20.00', 'max_orders' => 5]);
        $next = $this->holmesOffer(['percent_off' => '10.00']);
        $this->fillCart($this->example1Books());
        $this->asToken($this->token)->getJson('/api/v1/cart')->assertJsonPath('data.pricing.offer.id', $best->id); // cached
        $best->forceFill(['used_count' => 5])->save(); // used up a moment later; the cache doesn't know yet

        $this->checkout()->assertCreated()
            ->assertJsonPath('data.offer.id', $next->id)
            ->assertJsonPath('data.discount_usd', '2.00');
        $this->assertSame([5, 1], [$best->fresh()->used_count, $next->fresh()->used_count]);
        $this->assertLinesAddUp(Order::firstOrFail());
    }

    public function test_a_paused_offer_gives_422_when_its_id_is_sent(): void
    {
        $offer = $this->holmesOffer();
        $this->fillCart($this->example1Books());
        $offer->update(['is_active' => false]); // paused after the customer saw it

        $this->checkout(['offer_id' => $offer->id])->assertUnprocessable()->assertJsonValidationErrors('offer_id');
        $this->assertSame(0, Order::count());
    }

    public function test_cancel_gives_the_use_back_once_and_never_below_zero(): void
    {
        $offer = $this->holmesOffer();
        $this->fillCart($this->example1Books());
        $orderId = $this->checkout()->assertCreated()->json('data.id');
        $this->assertSame(1, $offer->fresh()->used_count);

        $this->asToken($this->token)->postJson("/api/v1/orders/{$orderId}/cancel")->assertOk();
        $this->assertSame(0, $offer->fresh()->used_count);

        $this->asToken($this->token)->postJson("/api/v1/orders/{$orderId}/cancel")->assertUnprocessable();
        $this->assertSame(0, $offer->fresh()->used_count, 'cancelling twice gives nothing back twice');

        // An offer already at 0 (e.g. an admin reset it) never goes below 0.
        $this->fillCart($this->example1Books());
        $secondId = $this->checkout()->assertCreated()->json('data.id');
        $offer->forceFill(['used_count' => 0])->save();
        $this->asToken($this->token)->postJson("/api/v1/orders/{$secondId}/cancel")->assertOk();
        $this->assertSame(0, $offer->fresh()->used_count);
    }

    public function test_cancel_after_the_offer_was_deleted_works_and_keeps_the_copy(): void
    {
        $offer = $this->holmesOffer();
        $this->fillCart($this->example1Books());
        $orderId = $this->checkout()->assertCreated()->json('data.id');

        $offer->delete(); // orders.offer_id becomes null (ON DELETE SET NULL)

        $this->asToken($this->token)->postJson("/api/v1/orders/{$orderId}/cancel")->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.offer', ['id' => null, 'name' => 'Buy 2 Holmes', 'min_quantity' => 2, 'percent_off' => '15.00']);
    }

    public function test_csv_export_has_the_offer_name_as_the_last_column(): void
    {
        $this->holmesOffer();
        $this->fillCart($this->example1Books());
        $this->checkout()->assertCreated();
        $today = now(config('shop.timezone'))->toDateString();

        $csv = $this->asToken($this->staffToken(admin: true))
            ->get("/api/v1/staff/orders/export?from={$today}&to={$today}")->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim(substr($csv, 3)))));

        $this->assertSame('offer_name', end($rows[0]));
        $this->assertSame('Buy 2 Holmes', end($rows[1]));
    }
}
