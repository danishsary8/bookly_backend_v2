<?php

namespace Tests\Feature\Catalog;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** When the normal search finds nothing, a misspelt word still finds the book (pg_trgm similarity). */
class TypoTolerantSearchTest extends TestCase
{
    use RefreshDatabase;

    private function book(array $attrs = []): Book
    {
        $book = Book::factory()->create($attrs);
        BookVariant::factory()->for($book)->create();

        return $book;
    }

    private function search(string $q, string $extra = '')
    {
        return $this->getJson('/api/v1/books?q='.urlencode($q).$extra)->assertOk();
    }

    public function test_a_misspelt_title_author_or_series_still_finds_the_book(): void
    {
        $shelley = Author::factory()->create(['name' => 'Mary Shelley']);
        $holmes = Series::factory()->create(['name' => 'Sherlock Holmes']);
        $frank = $this->book(['title' => 'Frankenstein']);
        $frank->authors()->attach($shelley);
        $hound = $this->book(['title' => 'The Hound of the Baskervilles', 'series_id' => $holmes->id]);
        $this->book(['title' => 'Cooking Basics']);

        $res = $this->search('frankenstien');
        $this->assertSame([$frank->id], collect($res->json('data'))->pluck('id')->all());
        $res->assertJsonPath('search.typo_tolerant', true);

        $this->assertSame([$frank->id], collect($this->search('mary shelly')->json('data'))->pluck('id')->all());
        $this->assertSame([$hound->id], collect($this->search('sherlok')->json('data'))->pluck('id')->all());
    }

    public function test_a_correct_search_does_not_use_the_fallback(): void
    {
        $frank = $this->book(['title' => 'Frankenstein']);

        $res = $this->search('frankenstein');
        $this->assertSame([$frank->id], collect($res->json('data'))->pluck('id')->all());
        $res->assertJsonPath('search.typo_tolerant', false);
    }

    public function test_nonsense_still_finds_nothing(): void
    {
        $this->book(['title' => 'Frankenstein']);
        $this->book(['title' => 'Emma']);

        $this->assertSame([], $this->search('xqzvwk')->json('data'));
        $this->assertSame([], $this->search('&|!:*()')->json('data'), 'operators only: no guessing');
    }

    public function test_the_fallback_keeps_the_other_filters(): void
    {
        $horror = Category::factory()->create(['name' => 'Horror', 'slug' => 'horror']);
        $poetry = Category::factory()->create(['name' => 'Poetry', 'slug' => 'poetry']);
        $frank = $this->book(['title' => 'Frankenstein']);
        $frank->categories()->attach($horror);

        $this->assertSame([$frank->id], collect($this->search('frankenstien', '&category_id='.$horror->id)->json('data'))->pluck('id')->all());
        $this->assertSame([], $this->search('frankenstien', '&category_id='.$poetry->id)->json('data'));
    }
}
