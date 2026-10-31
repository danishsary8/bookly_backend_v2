<?php

namespace Tests\Feature\Offers;

use App\Models\Book;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Series;
use App\Services\Offers\LiveOffer;
use App\Services\Offers\OfferService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Which offers count as live, and how they are read (spec 4.1, 4.10 and 6.6). */
class OfferServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): OfferService
    {
        return app(OfferService::class);
    }

    /** @return list<int> */
    private function liveIds(): array
    {
        return array_map(fn (LiveOffer $o) => $o->id, $this->service()->live());
    }

    private function queries(Closure $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function selected(array $targets, array $attributes = []): Offer
    {
        $offer = Offer::factory()->selected()->create($attributes);
        foreach ($targets as $target) {
            $offer->targets()->create($target);
        }

        return $offer;
    }

    public function test_only_live_offers_are_returned(): void
    {
        $live = Offer::factory()->create();
        $endsLater = Offer::factory()->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'max_orders' => 5, 'used_count' => 4]);
        Offer::factory()->paused()->create();
        Offer::factory()->create(['starts_at' => now()->addHour()]);                       // scheduled
        Offer::factory()->create(['starts_at' => now()->subDays(2), 'ends_at' => now()->subMinute()]); // ended
        Offer::factory()->create(['max_orders' => 3, 'used_count' => 3]);                   // used up
        Offer::factory()->selected()->create();                                             // "No books": no targets

        $this->assertSame([$live->id, $endsLater->id], $this->liveIds());
    }

    public function test_targets_in_the_trash_are_left_out_and_an_offer_with_only_those_is_ignored(): void
    {
        $trashedBook = Book::factory()->create();
        $trashedSeries = Series::factory()->create();
        $trashedCategory = Category::factory()->create();
        $onlyTrash = $this->selected([
            ['book_id' => $trashedBook->id], ['series_id' => $trashedSeries->id], ['category_id' => $trashedCategory->id],
        ]);
        $goodSeries = Series::factory()->create(['name' => 'Sherlock Holmes']);
        $mixed = $this->selected([['book_id' => $trashedBook->id], ['series_id' => $goodSeries->id]]);

        $trashedBook->delete();
        $trashedSeries->delete();
        $trashedCategory->delete();

        $this->assertSame([$mixed->id], $this->liveIds());
        $offer = $this->service()->live()[0];
        $this->assertSame([], $offer->bookIds);
        $this->assertSame([$goodSeries->id], $offer->seriesIds);
        $this->assertSame('Sherlock Holmes', $offer->targetLabel);
        $this->assertNotContains($onlyTrash->id, $this->liveIds());
    }

    public function test_the_copy_holds_the_numbers_the_math_needs(): void
    {
        $category = Category::factory()->create(['name' => 'History']);
        $offer = $this->selected([['category_id' => $category->id]], [
            'min_quantity' => 3, 'percent_off' => '12.50', 'ends_at' => '2030-01-02 03:04:05', 'max_orders' => 9,
        ]);

        $copy = $this->service()->live()[0];

        $this->assertSame($offer->id, $copy->id);
        $this->assertFalse($copy->appliesToAll);
        $this->assertSame(3, $copy->minQuantity);
        $this->assertSame('12.50', $copy->percentOff);
        $this->assertSame(1250, $copy->percentBasisPoints);
        $this->assertSame('2030-01-02T03:04:05Z', $copy->endsAtIso);
        $this->assertSame(9, $copy->maxOrders);
        $this->assertSame([$category->id], $copy->categoryIds);
        $this->assertSame('History', $copy->targetLabel);
    }

    public function test_target_label_only_for_exactly_one_series_or_category(): void
    {
        $book = Book::factory()->create();
        [$a, $b] = Series::factory()->count(2)->create();
        $this->selected([['book_id' => $book->id]]);
        $this->selected([['series_id' => $a->id], ['series_id' => $b->id]]);
        Offer::factory()->create();

        foreach ($this->service()->live() as $offer) {
            $this->assertNull($offer->targetLabel, "offer {$offer->id}");
        }
    }

    public function test_reading_is_one_query_cached_for_60_seconds_whatever_the_number_of_offers(): void
    {
        $series = Series::factory()->count(5)->create();
        foreach (range(1, 4) as $i) {
            $this->selected($series->map(fn ($s) => ['series_id' => $s->id])->all());
        }
        Offer::factory()->count(3)->create();

        $this->assertSame(1, $this->queries(fn () => $this->service()->live()));
        $this->assertCount(7, $this->service()->live());
        $this->assertSame(0, $this->queries(fn () => $this->service()->live()), 'second read comes from the cache');

        $this->travel(61)->seconds();
        $this->assertSame(1, $this->queries(fn () => $this->service()->live()), 'read again after 60 seconds');

        $this->service()->forget();
        $this->assertSame(1, $this->queries(fn () => $this->service()->live()), 'forget() clears the cache');
    }

    public function test_dates_are_checked_on_every_read_even_from_the_cache(): void
    {
        $starts = Offer::factory()->create(['starts_at' => now()->addSeconds(30)]);
        $ends = Offer::factory()->create(['ends_at' => now()->addSeconds(30)]);

        $this->assertSame([$ends->id], $this->liveIds());

        $this->travel(31)->seconds();
        $this->assertSame(0, $this->queries(fn () => $this->assertSame([$starts->id], $this->liveIds())));
    }
}
