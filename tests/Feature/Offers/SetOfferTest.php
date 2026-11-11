<?php

namespace Tests\Feature\Offers;

use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Series;
use App\Services\Offers\OfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/** Set offers (owner 2026-11-12): every volume of a series in one order gets X% off, with optional free delivery. */
class SetOfferTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private Series $batman;

    private string $token;

    private CustomerAddress $address;

    /** @var list<BookVariant> three volumes at $10 */
    private array $volumes;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.free_shipping_threshold' => '50.00']);
        $this->batman = Series::factory()->create(['name' => 'Batman']);
        $customer = Customer::factory()->create();
        $this->token = $customer->createToken('t', ['customer'])->plainTextToken;
        $this->address = CustomerAddress::factory()->create(['customer_id' => $customer->id]); // Phnom Penh: $1.50
        $this->volumes = [$this->variant('10.00', $this->batman), $this->variant('10.00', $this->batman), $this->variant('10.00', $this->batman)];
    }

    private function variant(string $price, ?Series $series = null): BookVariant
    {
        return BookVariant::factory()->create(['book_id' => Book::factory()->create(['series_id' => $series?->id])->id, 'price_usd' => $price, 'stock_quantity' => 20]);
    }

    private function setOffer(array $attributes = []): Offer
    {
        $offer = Offer::factory()->selected()->create(['name' => 'Batman set', 'min_quantity' => 2, 'percent_off' => '20.00', 'whole_series' => true, ...$attributes]);
        $offer->targets()->create(['series_id' => $this->batman->id]);
        app(OfferService::class)->forget();

        return $offer;
    }

    private function add(BookVariant ...$variants): void
    {
        foreach ($variants as $variant) {
            $this->asToken($this->token)->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id])->assertCreated();
        }
    }

    public function test_the_discount_applies_only_when_every_volume_is_in_the_cart(): void
    {
        $this->setOffer();
        $other = $this->variant('10.00');

        // Two of three volumes plus another book: no discount, a nudge to add the missing volume.
        $this->add($this->volumes[0], $this->volumes[1], $other);
        $cart = $this->asToken($this->token)->getJson('/api/v1/cart')->assertOk();
        $cart->assertJsonPath('data.pricing.offer', null)
            ->assertJsonPath('data.pricing.discount_usd', '0.00')
            ->assertJsonPath('data.pricing.nudge.add_quantity', 1)
            ->assertJsonPath('data.pricing.nudge.whole_series', true)
            ->assertJsonPath('data.pricing.nudge.target_label', 'Batman');

        // The third volume completes the set: 20% off the three volumes ($6), not the other book.
        $this->add($this->volumes[2]);
        $cart = $this->asToken($this->token)->getJson('/api/v1/cart')->assertOk();
        $cart->assertJsonPath('data.pricing.offer.whole_series', true)
            ->assertJsonPath('data.pricing.offer.percent_off', '20.00')
            ->assertJsonPath('data.pricing.offer.min_quantity', 3)
            ->assertJsonPath('data.pricing.discount_usd', '6.00')
            ->assertJsonPath('data.pricing.nudge', null);
    }

    public function test_free_delivery_comes_with_the_set_and_is_saved_on_the_order(): void
    {
        $this->setOffer(['free_delivery' => true]);
        $this->add(...$this->volumes); // $30 - 20% = $24, under the $50 free-delivery line

        $this->asToken($this->token)->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('data.pricing.free_shipping.is_free', true)
            ->assertJsonPath('data.pricing.offer.free_delivery', true);

        $this->asToken($this->token)->withHeader('Idempotency-Key', Str::random(24))
            ->postJson('/api/v1/checkout', ['address_id' => $this->address->id, 'payment_method' => 'cod'])->assertCreated();
        $order = Order::sole();
        $this->assertSame('6.00', $order->discount_amount);
        $this->assertSame('0.00', $order->shipping_fee);
        $this->assertSame('24.00', $order->total_amount);

        // Without the option the same set pays delivery.
        Offer::query()->update(['free_delivery' => false]);
        app(OfferService::class)->forget();
        $this->add(...$this->volumes);
        $this->asToken($this->token)->withHeader('Idempotency-Key', Str::random(24))
            ->postJson('/api/v1/checkout', ['address_id' => $this->address->id, 'payment_method' => 'cod'])->assertCreated();
        $this->assertSame('1.50', Order::latest('id')->first()->shipping_fee);
    }

    public function test_the_series_and_its_books_show_the_set_offer(): void
    {
        $this->setOffer(['free_delivery' => true, 'ends_at' => now()->addDays(5)]);

        $series = $this->getJson("/api/v1/series/{$this->batman->id}")->assertOk();
        $series->assertJsonPath('data.set_offer.percent_off', '20.00')
            ->assertJsonPath('data.set_offer.free_delivery', true)
            ->assertJsonPath('data.set_offer.volumes', 3)
            ->assertJsonPath('data.set_offer.full_price_usd', '30.00')
            ->assertJsonPath('data.set_offer.set_price_usd', '24.00')
            ->assertJsonPath('data.set_offer.saving_usd', '6.00')
            ->assertJsonPath('data.books.0.offer.whole_series', true);
        $this->assertNotNull($series->json('data.set_offer.ends_at'));

        // A series without a set offer says so.
        $this->getJson('/api/v1/series/'.Series::factory()->create()->id)->assertOk()->assertJsonPath('data.set_offer', null);
    }

    public function test_complete_the_set_can_add_volumes_the_customer_already_owns(): void
    {
        $this->setOffer();
        $this->add($this->volumes[0]);
        $this->asToken($this->token)->withHeader('Idempotency-Key', Str::random(24))
            ->postJson('/api/v1/checkout', ['address_id' => $this->address->id, 'payment_method' => 'cod'])->assertCreated();
        Order::sole()->update(['status' => 'delivered']);

        // Default: only the two volumes not owned yet, so the set is not complete.
        $this->asToken($this->token)->postJson("/api/v1/cart/series/{$this->batman->id}")->assertOk();
        $this->asToken($this->token)->getJson('/api/v1/cart')->assertJsonCount(2, 'data.items')->assertJsonPath('data.pricing.offer', null);

        // whole_set: the owned volume too, and the set offer applies.
        $this->asToken($this->token)->postJson("/api/v1/cart/series/{$this->batman->id}", ['whole_set' => true])->assertOk();
        $this->asToken($this->token)->getJson('/api/v1/cart')->assertJsonCount(3, 'data.items')->assertJsonPath('data.pricing.discount_usd', '6.00');
    }

    public function test_admin_creates_a_set_offer_for_series_only(): void
    {
        $admin = $this->staffToken(admin: true);
        $body = ['name' => 'Batman set', 'applies_to' => 'selected', 'percent_off' => '20', 'whole_series' => true, 'free_delivery' => true,
            'targets' => [['type' => 'series', 'id' => $this->batman->id]]];

        $this->asToken($admin)->postJson('/api/v1/staff/offers', $body)->assertCreated()
            ->assertJsonPath('data.whole_series', true)->assertJsonPath('data.free_delivery', true);

        // Not for single books, and not for "all books".
        $book = $this->volumes[0]->book_id;
        $this->asToken($admin)->postJson('/api/v1/staff/offers', [...$body, 'targets' => [['type' => 'book', 'id' => $book]]])->assertJsonValidationErrors('whole_series');
        $this->asToken($admin)->postJson('/api/v1/staff/offers', [...$body, 'applies_to' => 'all', 'targets' => null])->assertStatus(422);
    }
}
