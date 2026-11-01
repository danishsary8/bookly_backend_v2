<?php

namespace Tests\Feature\Offers;

use App\Enums\OrderStatus;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Series;
use App\Services\Offers\OfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/** GET /staff/reports/offers (offers spec section 7): orders placed through the real checkout. */
class OfferReportTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private Series $holmes;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.free_shipping_threshold' => '50.00']);
        $this->holmes = Series::factory()->create(['name' => 'Sherlock Holmes']);
        $this->offer = Offer::factory()->selected()->create(['name' => 'Buy 2 Holmes', 'min_quantity' => 2, 'percent_off' => '15.00']);
        $this->offer->targets()->create(['series_id' => $this->holmes->id]);
        app(OfferService::class)->forget();
    }

    private function variant(string $price, bool $holmes): BookVariant
    {
        $book = Book::factory()->create(['series_id' => $holmes ? $this->holmes->id : null]);

        return BookVariant::factory()->create(['book_id' => $book->id, 'price_usd' => $price, 'stock_quantity' => 50]);
    }

    /** Places one order for a new customer through POST /checkout (Phnom Penh delivery $1.50). */
    private function order(array $variants): Order
    {
        $customer = Customer::factory()->create();
        $address = CustomerAddress::factory()->create(['customer_id' => $customer->id]);
        $token = $customer->createToken('t', ['customer'])->plainTextToken;
        foreach ($variants as $variant) {
            $this->asToken($token)->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id])->assertCreated();
        }
        $id = $this->asToken($token)->withHeader('Idempotency-Key', Str::random(24))
            ->postJson('/api/v1/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.id');

        return Order::findOrFail($id);
    }

    private function report(string $query = ''): array
    {
        $today = now(config('shop.timezone'))->toDateString();

        return $this->asToken($this->staffToken(admin: true))
            ->getJson("/api/v1/staff/reports/offers?from={$today}&to={$today}{$query}")->assertOk()->json('data');
    }

    public function test_per_offer_numbers_and_orders_without_an_offer(): void
    {
        [$a, $b, $cook] = [$this->variant('10.00', true), $this->variant('10.00', true), $this->variant('30.00', false)];
        $one = $this->order([$a, $b, $cook]);  // spec example 1: -3.00, total 48.50, 3 books (2 with the offer)
        $two = $this->order([$a, $b]);         // -3.00, 17.00 + 1.50 = 18.50, 2 books (2 with the offer)
        $this->order([$cook]);                 // no offer: 30.00 + 1.50 = 31.50, 1 book
        $cancelled = $this->order([$a, $b]);   // cancelled orders never count
        $cancelled->update(['status' => OrderStatus::Cancelled]);
        $this->assertSame('48.50', $one->total_amount);
        $this->assertSame('18.50', $two->total_amount);

        $data = $this->report();
        $this->assertCount(1, $data['offers']);
        $this->assertSame([
            'offer_id' => $this->offer->id, 'name' => 'Buy 2 Holmes', 'min_quantity' => 2, 'percent_off' => '15.00',
            'orders' => 2, 'units' => 5, 'units_with_offer' => 4,
            'revenue_usd' => '67.00', 'revenue_khr' => null, 'discount_usd' => '6.00', 'discount_khr' => null,
            'average_order_usd' => '33.50', 'average_order_khr' => null, 'books_per_order' => '2.50',
        ], $data['offers'][0]);
        $this->assertSame(1, $data['without_offer']['orders']);
        $this->assertSame(1, $data['without_offer']['units']);
        $this->assertSame(0, $data['without_offer']['units_with_offer']);
        $this->assertSame('31.50', $data['without_offer']['revenue_usd']);
        $this->assertSame('0.00', $data['without_offer']['discount_usd']);
        $this->assertSame('31.50', $data['without_offer']['average_order_usd']);
        $this->assertSame('1.00', $data['without_offer']['books_per_order']);
    }

    public function test_range_and_empty_report(): void
    {
        $this->order([$this->variant('10.00', true), $this->variant('10.00', true)]);
        $data = $this->asToken($this->staffToken(admin: true))
            ->getJson('/api/v1/staff/reports/offers?from=2020-01-01&to=2020-01-31')->assertOk()->json('data');

        $this->assertSame([], $data['offers']);
        $this->assertSame(['orders' => 0, 'units' => 0, 'units_with_offer' => 0, 'revenue_usd' => '0.00', 'revenue_khr' => null,
            'discount_usd' => '0.00', 'discount_khr' => null, 'average_order_usd' => '0.00', 'average_order_khr' => null,
            'books_per_order' => '0.00'], $data['without_offer']);
        $this->assertSame('2020-01-31', $data['period']['to']);
    }

    public function test_an_edited_offer_keeps_its_orders_and_shows_the_current_name(): void
    {
        $this->order([$this->variant('10.00', true), $this->variant('10.00', true)]);
        $this->offer->update(['name' => 'Holmes week', 'percent_off' => '20.00']);
        Cache::flush();

        $row = $this->report()['offers'][0];
        $this->assertSame('Holmes week', $row['name']);
        $this->assertSame('15.00', $row['percent_off']); // the rule the orders were sold with
        $this->assertSame(1, $row['orders']);
    }

    public function test_only_admins_and_csv(): void
    {
        $this->order([$this->variant('10.00', true), $this->variant('10.00', true)]);
        $this->order([$this->variant('30.00', false)]);
        $today = now(config('shop.timezone'))->toDateString();

        $this->asToken($this->staffToken())->getJson("/api/v1/staff/reports/offers?from={$today}&to={$today}")->assertForbidden();
        $this->asToken($this->staffToken(admin: true))->getJson('/api/v1/staff/reports/offers?from=2026-02-01&to=2026-01-01')
            ->assertUnprocessable()->assertJsonValidationErrors('to');

        $csv = $this->asToken($this->staffToken(admin: true))
            ->get("/api/v1/staff/reports/offers?from={$today}&to={$today}&format=csv")->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF")))));
        $this->assertSame(['Offer', 'Rule', 'Orders', 'Books', 'Books with the offer', 'Revenue (USD)', 'Discount (USD)', 'Average order (USD)', 'Books per order'], $lines[0]);
        $this->assertSame(['Buy 2 Holmes', 'Buy 2, save 15%', '1', '2', '2', '18.50', '3.00', '18.50', '2.00'], $lines[1]);
        $this->assertSame(['Orders without an offer', '', '1', '1', '0', '31.50', '0.00', '31.50', '1.00'], $lines[2]);
    }
}
