<?php

namespace Tests\Feature\Offers;

use App\Enums\BookFormat;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Series;
use App\Services\Offers\OfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** GET /cart `pricing` block and `in_offer` (docs/superpowers/specs/2026-10-31-offers-design.md 6.1, examples 1 and 5). */
class CartPricingTest extends TestCase
{
    use RefreshDatabase;

    private Series $holmes;

    private Category $cooking;

    protected function setUp(): void
    {
        parent::setUp();
        $customer = Customer::factory()->create();
        $this->withToken($customer->createToken('t', ['customer'])->plainTextToken);
        config(['shop.free_shipping_threshold' => '50.00']);

        $this->holmes = Series::factory()->create(['name' => 'Sherlock Holmes']);
        $this->cooking = Category::factory()->create(['name' => 'Cooking']);
    }

    private function variant(string $price, ?Series $series = null, ?Category $category = null, BookFormat $format = BookFormat::Paperback): BookVariant
    {
        $book = Book::factory()->create(['series_id' => $series?->id]);
        if ($category) {
            $book->categories()->attach($category);
        }

        return BookVariant::factory()->format($format)->create(['book_id' => $book->id, 'price_usd' => $price]);
    }

    private function add(BookVariant $variant, int $quantity = 1): void
    {
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => $quantity])->assertCreated();
    }

    private function holmesOffer(array $attributes = []): Offer
    {
        $offer = Offer::factory()->selected()->create(['name' => 'Buy 2 Holmes', 'min_quantity' => 2, 'percent_off' => '15.00', ...$attributes]);
        $offer->targets()->create(['series_id' => $this->holmes->id]);
        app(OfferService::class)->forget();

        return $offer;
    }

    /** Example 1: Holmes A $10 + Holmes B $10 + cookbook $30, "Buy 2 Holmes, get 15%". */
    public function test_example_1_pricing_block_and_in_offer(): void
    {
        $offer = $this->holmesOffer(['ends_at' => '2030-01-01 00:00:00']);
        $this->add($this->variant('10.00', $this->holmes));
        $this->add($this->variant('10.00', $this->holmes));
        $this->add($this->variant('30.00', category: $this->cooking));

        $this->getJson('/api/v1/cart')->assertOk()
            // Old fields keep their meaning: subtotal is before any discount, line totals are full price.
            ->assertJsonPath('data.subtotal_usd', '50.00')
            ->assertJsonPath('data.item_count', 3)
            ->assertJsonPath('data.can_checkout', true)
            ->assertJsonPath('data.items.0.line_total_usd', '10.00')
            ->assertJsonPath('data.items.2.line_total_usd', '30.00')
            // New.
            ->assertJsonPath('data.items.0.in_offer', true)
            ->assertJsonPath('data.items.1.in_offer', true)
            ->assertJsonPath('data.items.2.in_offer', false)
            ->assertJsonPath('data.pricing.subtotal_usd', '50.00')
            ->assertJsonPath('data.pricing.discount_usd', '3.00')
            ->assertJsonPath('data.pricing.total_before_shipping_usd', '47.00')
            ->assertJsonPath('data.pricing.offer', [
                'id' => $offer->id, 'type' => 'buy_n_percent', 'min_quantity' => 2, 'percent_off' => '15.00',
                'ends_at' => '2030-01-01T00:00:00Z', 'target_label' => 'Sherlock Holmes',
                'discount_usd' => '3.00', 'discount_khr' => null,
            ])
            ->assertJsonPath('data.pricing.nudge', null)
            ->assertJsonPath('data.pricing.free_shipping', [
                'threshold_usd' => '50.00', 'threshold_khr' => null,
                'amount_needed_usd' => '3.00', 'amount_needed_khr' => null,
                'is_free' => false,
            ]);
    }

    /** Example 5: Holmes A only -> add 1 more from Sherlock Holmes to save 15%. */
    public function test_example_5_nudge(): void
    {
        $offer = $this->holmesOffer();
        $this->add($this->variant('10.00', $this->holmes));

        $this->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('data.pricing.offer', null)
            ->assertJsonPath('data.pricing.discount_usd', '0.00')
            ->assertJsonPath('data.items.0.in_offer', false)
            ->assertJsonPath('data.pricing.nudge', [
                'offer_id' => $offer->id, 'add_quantity' => 1, 'percent_off' => '15.00', 'target_label' => 'Sherlock Holmes',
            ]);
    }

    public function test_no_live_offer_means_no_discount_and_no_nudge(): void
    {
        Offer::factory()->paused()->create(); // a paused offer for every book changes nothing
        $this->add($this->variant('12.50'), 2);

        $this->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('data.subtotal_usd', '25.00')
            ->assertJsonPath('data.items.0.in_offer', false)
            ->assertJsonPath('data.pricing.discount_usd', '0.00')
            ->assertJsonPath('data.pricing.total_before_shipping_usd', '25.00')
            ->assertJsonPath('data.pricing.offer', null)
            ->assertJsonPath('data.pricing.nudge', null)
            ->assertJsonPath('data.pricing.free_shipping.amount_needed_usd', '25.00')
            ->assertJsonPath('data.pricing.free_shipping.is_free', false);
    }

    public function test_empty_cart_has_a_zero_pricing_block(): void
    {
        $this->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.pricing.subtotal_usd', '0.00')
            ->assertJsonPath('data.pricing.discount_usd', '0.00')
            ->assertJsonPath('data.pricing.offer', null)
            ->assertJsonPath('data.pricing.free_shipping', null);
    }

    public function test_free_delivery_uses_the_config_threshold_and_the_discount(): void
    {
        // $52 of Holmes with a 10% offer = $46.80: under $50, so delivery is not free yet (spec section 10).
        $this->holmesOffer(['percent_off' => '10.00']);
        $this->add($this->variant('26.00', $this->holmes), 2);

        $this->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('data.pricing.discount_usd', '5.20')
            ->assertJsonPath('data.pricing.total_before_shipping_usd', '46.80')
            ->assertJsonPath('data.pricing.free_shipping.amount_needed_usd', '3.20')
            ->assertJsonPath('data.pricing.free_shipping.is_free', false);

        config(['shop.free_shipping_threshold' => '40.00']);
        $this->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('data.pricing.free_shipping.threshold_usd', '40.00')
            ->assertJsonPath('data.pricing.free_shipping.amount_needed_usd', '0.00')
            ->assertJsonPath('data.pricing.free_shipping.is_free', true);

        config(['shop.free_shipping_threshold' => null]);
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.pricing.free_shipping', null);
    }

    public function test_ebook_only_cart_has_no_free_delivery_block_but_ebooks_still_count_for_the_offer(): void
    {
        $this->holmesOffer();
        $this->add($this->variant('7.99', $this->holmes, format: BookFormat::Ebook));
        $this->add($this->variant('9.99', $this->holmes, format: BookFormat::Audiobook));

        $this->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('data.pricing.free_shipping', null)
            ->assertJsonPath('data.pricing.discount_usd', '2.68') // 119 (7.99 x 15%) + 149 (9.99 x 15%)
            ->assertJsonPath('data.items.0.in_offer', true)
            ->assertJsonPath('data.items.1.in_offer', true);
    }

    public function test_out_of_stock_line_does_not_count(): void
    {
        $this->holmesOffer();
        $this->add($this->variant('10.00', $this->holmes));
        $soldOut = $this->variant('10.00', $this->holmes);
        $this->add($soldOut);
        $soldOut->update(['stock_quantity' => 0]);

        $this->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('data.can_checkout', false)
            ->assertJsonPath('data.pricing.offer', null)
            ->assertJsonPath('data.items.1.in_offer', false)
            ->assertJsonPath('data.pricing.nudge.add_quantity', 1);
    }

    public function test_the_other_cart_endpoints_return_the_same_block(): void
    {
        $this->holmesOffer();
        $this->add($this->variant('10.00', $this->holmes));

        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $this->variant('10.00', $this->holmes)->id])
            ->assertCreated()
            ->assertJsonPath('data.pricing.discount_usd', '3.00')
            ->assertJsonPath('data.items.1.in_offer', true);
    }

    public function test_signed_in_customer_only_a_guest_still_gets_401(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/cart')->assertUnauthorized();
    }

    public function test_query_count_does_not_grow_with_the_number_of_offers(): void
    {
        $this->add($this->variant('10.00', $this->holmes, $this->cooking));
        $this->add($this->variant('12.00', $this->holmes));
        $this->add($this->variant('30.00', category: $this->cooking));

        $count = function (): int {
            app(OfferService::class)->forget(); // cold cache each time, so the offer query is counted too
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/cart')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->holmesOffer();
        $withOne = $count();

        foreach (range(1, 8) as $i) {
            $offer = Offer::factory()->selected()->create(['percent_off' => (string) (5 + $i)]);
            $offer->targets()->create(['category_id' => $this->cooking->id]);
            $offer->targets()->create(['book_id' => Book::factory()->create()->id]);
        }
        Offer::factory()->count(3)->create();
        $withTwelve = $count();

        $this->assertSame($withOne, $withTwelve, "1 offer: {$withOne} queries, 12 offers: {$withTwelve}");
    }
}
