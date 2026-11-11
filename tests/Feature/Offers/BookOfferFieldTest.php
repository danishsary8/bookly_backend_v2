<?php

namespace Tests\Feature\Offers;

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

/** Book list and book detail `offer` field (docs/superpowers/specs/2026-10-31-offers-design.md 6.6). */
class BookOfferFieldTest extends TestCase
{
    use RefreshDatabase;

    private Series $holmes;

    private Category $philosophy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->holmes = Series::factory()->create(['name' => 'Sherlock Holmes']);
        $this->philosophy = Category::factory()->create(['name' => 'Philosophy']);
    }

    private function book(?Series $series = null, ?Category $category = null): Book
    {
        $book = Book::factory()->create(['series_id' => $series?->id]);
        if ($category) {
            $book->categories()->attach($category);
        }
        BookVariant::factory()->create(['book_id' => $book->id, 'price_usd' => '10.00']);

        return $book;
    }

    private function offer(array $attributes = [], ?Series $series = null, ?Category $category = null): Offer
    {
        $offer = Offer::factory()->create($attributes);
        if ($series) {
            $offer->targets()->create(['series_id' => $series->id]);
        }
        if ($category) {
            $offer->targets()->create(['category_id' => $category->id]);
        }
        app(OfferService::class)->forget();

        return $offer;
    }

    /** @return array<int, mixed> book id => offer field, from GET /books */
    private function listOffers(string $query = ''): array
    {
        $rows = $this->getJson('/api/v1/books'.$query)->assertOk()->json('data');

        return collect($rows)->mapWithKeys(fn ($row) => [$row['id'] => $row['offer']])->all();
    }

    public function test_no_live_offer_gives_null_on_list_and_detail(): void
    {
        $book = $this->book($this->holmes);
        $this->offer(['is_active' => false]); // paused: never shown

        $this->assertSame([$book->id => null], $this->listOffers());
        $this->getJson("/api/v1/books/{$book->id}")->assertOk()->assertJsonPath('data.offer', null);
    }

    public function test_series_offer_shows_on_its_books_only(): void
    {
        $a = $this->book($this->holmes);
        $b = $this->book($this->holmes);
        $other = $this->book();
        $offer = $this->offer(['applies_to' => Offer::APPLIES_TO_SELECTED, 'min_quantity' => 2, 'percent_off' => '15.00',
            'ends_at' => '2030-01-01 00:00:00'], series: $this->holmes);

        $expected = ['id' => $offer->id, 'min_quantity' => 2, 'percent_off' => '15.00',
            'ends_at' => '2030-01-01T00:00:00Z', 'target_label' => 'Sherlock Holmes', 'whole_series' => false, 'free_delivery' => false];
        $offers = $this->listOffers();
        $this->assertSame($expected, $offers[$a->id]);
        $this->assertSame($expected, $offers[$b->id]);
        $this->assertNull($offers[$other->id]);

        $this->getJson("/api/v1/books/{$a->id}")->assertOk()->assertJsonPath('data.offer', $expected);
        $this->getJson("/api/v1/books/{$other->id}")->assertOk()->assertJsonPath('data.offer', null);
    }

    public function test_category_offer_and_the_biggest_percent_wins(): void
    {
        $thinker = $this->book(category: $this->philosophy);
        $plain = $this->book();
        $all = $this->offer(['percent_off' => '10.00']); // all books
        $category = $this->offer(['applies_to' => Offer::APPLIES_TO_SELECTED, 'percent_off' => '20.00'], category: $this->philosophy);

        $offers = $this->listOffers();
        $this->assertSame($category->id, $offers[$thinker->id]['id']);
        $this->assertSame('Philosophy', $offers[$thinker->id]['target_label']);
        $this->assertSame($all->id, $offers[$plain->id]['id']);
        $this->assertNull($offers[$plain->id]['target_label']);

        // The detail page loads the full categories itself; the offer is the same.
        $this->getJson("/api/v1/books/{$thinker->id}")->assertOk()
            ->assertJsonPath('data.offer.id', $category->id)
            ->assertJsonPath('data.categories.0.name', 'Philosophy');
    }

    public function test_scheduled_and_used_up_offers_are_not_shown(): void
    {
        $book = $this->book($this->holmes);
        $this->offer(['applies_to' => Offer::APPLIES_TO_SELECTED, 'starts_at' => now()->addDay()], series: $this->holmes);
        $this->offer(['max_orders' => 1, 'used_count' => 1]);

        $this->assertSame([$book->id => null], $this->listOffers());
    }

    public function test_series_page_and_wishlist_show_the_offer(): void
    {
        $book = $this->book($this->holmes);
        $offer = $this->offer(['applies_to' => Offer::APPLIES_TO_SELECTED], category: $this->philosophy);
        $book->categories()->attach($this->philosophy);

        $this->getJson("/api/v1/series/{$this->holmes->id}")->assertOk()->assertJsonPath('data.books.0.offer.id', $offer->id);

        $customer = Customer::factory()->create();
        $customer->wishlistBooks()->attach($book->id);
        $this->withToken($customer->createToken('t', ['customer'])->plainTextToken)
            ->getJson('/api/v1/wishlist')->assertOk()->assertJsonPath('data.0.offer.id', $offer->id);
    }

    private function countListQueries(): int
    {
        $this->getJson('/api/v1/books?per_page=50')->assertOk(); // warm the offer cache
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/books?per_page=50')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_query_count_does_not_grow_with_books_or_offers(): void
    {
        $this->offer(['applies_to' => Offer::APPLIES_TO_SELECTED], category: $this->philosophy);
        foreach (range(1, 3) as $i) {
            $this->book(category: $this->philosophy);
        }
        $few = $this->countListQueries();

        foreach (range(1, 12) as $i) {
            $this->book($this->holmes, $this->philosophy);
            $this->offer(['applies_to' => Offer::APPLIES_TO_SELECTED, 'percent_off' => (string) (5 + $i)], series: $this->holmes);
        }
        $many = $this->countListQueries();

        $this->assertSame($few, $many, 'Books or offers must not add queries to a book list.');
    }

    public function test_no_category_offer_means_no_extra_query(): void
    {
        foreach (range(1, 3) as $i) {
            $this->book(category: $this->philosophy);
        }
        $without = $this->countListQueries();

        $this->offer(); // all books: no category needed
        $this->assertSame($without, $this->countListQueries());

        $this->offer(['applies_to' => Offer::APPLIES_TO_SELECTED], category: $this->philosophy);
        $this->assertSame($without + 1, $this->countListQueries(), 'A category offer costs exactly one query per list.');
    }
}
