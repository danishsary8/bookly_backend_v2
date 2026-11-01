<?php

namespace Tests\Feature\Catalog;

use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\BookCollection;
use App\Models\BookVariant;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/** Seasonal collections (owner 2026-11-01): shop endpoints and the admin endpoints. */
class SeasonalCollectionsTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private function book(string $title): Book
    {
        $book = Book::factory()->create(['title' => $title]);
        BookVariant::factory()->create(['book_id' => $book->id, 'price_usd' => '10.00']);

        return $book;
    }

    /** @param list<Book> $books */
    private function collection(array $attributes, array $books): BookCollection
    {
        $collection = BookCollection::factory()->create($attributes);
        foreach ($books as $i => $book) {
            DB::table('collection_books')->insert(['collection_id' => $collection->id, 'book_id' => $book->id, 'position' => $i]);
        }

        return $collection;
    }

    private function admin(): static
    {
        return $this->asToken($this->staffToken(admin: true));
    }

    public function test_home_shows_up_to_two_live_collections_in_order_with_books_in_the_admins_order(): void
    {
        [$a, $b, $c] = [$this->book('Rain A'), $this->book('Rain B'), $this->book('Exam C')];
        $rain = $this->collection(['slug' => 'rainy-season-reads', 'title_en' => 'Rainy season reads', 'title_km' => 'សៀវភៅរដូវវស្សា', 'position' => 2], [$b, $a]);
        $exam = $this->collection(['slug' => 'exam-season', 'title_en' => 'Exam season', 'position' => 1], [$c]);
        $this->collection(['slug' => 'third', 'position' => 3], [$a]);                              // a third live one: not on the home page
        $this->collection(['slug' => 'hidden', 'is_active' => false], [$a]);                         // switched off
        $this->collection(['slug' => 'later', 'starts_at' => now()->addDay()], [$a]);                // scheduled
        $this->collection(['slug' => 'over', 'starts_at' => now()->subDays(9), 'ends_at' => now()->subDay()], [$a]); // ended

        $this->getJson('/api/v1/collections/live')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'exam-season')
            ->assertJsonPath('data.1.slug', 'rainy-season-reads')
            ->assertJsonPath('data.1.title_km', 'សៀវភៅរដូវវស្សា')
            ->assertJsonPath('data.1.books_count', 2)
            ->assertJsonPath('data.1.books.0.title', 'Rain B')
            ->assertJsonPath('data.1.books.1.title', 'Rain A');
        $this->assertNotNull($rain->id);
        $this->assertNotNull($exam->id);
    }

    public function test_hidden_books_are_left_out_and_an_empty_collection_is_not_shown(): void
    {
        $visible = $this->book('On sale');
        $draft = $this->book('Draft');
        $draft->update(['status' => 'draft']);
        $this->collection(['slug' => 'mixed', 'title_en' => 'Mixed'], [$draft, $visible]);
        $this->collection(['slug' => 'only-draft', 'title_en' => 'Only draft', 'position' => -1], [$draft]);

        $this->getJson('/api/v1/collections/live')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'mixed')
            ->assertJsonPath('data.0.books_count', 1)
            ->assertJsonCount(1, 'data.0.books');
        $this->getJson('/api/v1/collections/only-draft')->assertNotFound();
    }

    public function test_collection_page_by_slug_only_while_live(): void
    {
        $books = array_map(fn ($i) => $this->book("Book {$i}"), range(1, 14));
        $this->collection(['slug' => 'rainy-season-reads', 'title_en' => 'Rainy season reads', 'description_en' => 'Cosy books for wet afternoons.'], $books);
        $this->collection(['slug' => 'hidden-one', 'is_active' => false], $books);

        $this->getJson('/api/v1/collections/rainy-season-reads')->assertOk()
            ->assertJsonPath('data.description_en', 'Cosy books for wet afternoons.')
            ->assertJsonCount(14, 'data.books')     // the page shows all (the home row shows 12)
            ->assertJsonPath('data.books.13.title', 'Book 14');
        $this->getJson('/api/v1/collections/live')->assertJsonCount(12, 'data.0.books');
        $this->getJson('/api/v1/collections/hidden-one')->assertNotFound();
        $this->getJson('/api/v1/collections/nope')->assertNotFound();
    }

    public function test_query_count_does_not_grow_with_books(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/collections/live')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->collection(['slug' => 'one'], [$this->book('A')]);
        $this->collection(['slug' => 'two'], [$this->book('B')]);
        $count();
        $few = $count();
        foreach (BookCollection::all() as $collection) {
            foreach (range(1, 6) as $i) {
                DB::table('collection_books')->insert(['collection_id' => $collection->id, 'book_id' => $this->book("More {$collection->id}-{$i}")->id, 'position' => $i]);
            }
        }
        $this->assertSame($few, $count());
    }

    public function test_admin_creates_a_collection_hidden_by_default_with_ordered_books_and_it_is_audited(): void
    {
        [$a, $b] = [$this->book('Alpha'), $this->book('Beta')];

        $this->admin()->postJson('/api/v1/staff/collections', [
            'title_en' => 'Rainy Season Reads', 'title_km' => 'សៀវភៅរដូវវស្សា', 'description_en' => 'Cosy books.', 'books' => [$b->id, $a->id],
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'rainy-season-reads')       // made from the English title
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.status', 'hidden')
            ->assertJsonPath('data.books.0.title', 'Beta')
            ->assertJsonPath('data.books.1.title', 'Alpha');
        $this->assertSame([$b->id, $a->id], AdminAuditLog::where('action', 'book_collection.created')->sole()->after_data['books']);
        $this->getJson('/api/v1/collections/live')->assertJsonCount(0, 'data');
    }

    public function test_admin_turns_it_on_reorders_books_and_validation_limits(): void
    {
        [$a, $b] = [$this->book('Alpha'), $this->book('Beta')];
        $collection = $this->collection(['slug' => 'rain', 'is_active' => false], [$a, $b]);

        $this->admin()->patchJson("/api/v1/staff/collections/{$collection->id}", ['is_active' => true, 'books' => [$b->id, $a->id]])
            ->assertOk()->assertJsonPath('data.status', 'live')->assertJsonPath('data.books.0.title', 'Beta');
        $this->getJson('/api/v1/collections/rain')->assertOk()->assertJsonPath('data.books.0.title', 'Beta');
        $log = AdminAuditLog::where('action', 'book_collection.updated')->sole();
        $this->assertSame([$a->id, $b->id], $log->before_data['books']);

        $this->admin()->postJson('/api/v1/staff/collections', ['title_en' => '', 'slug' => 'Bad Slug!', 'books' => [999999]])
            ->assertUnprocessable()->assertJsonValidationErrors(['title_en', 'slug']);
        $this->admin()->postJson('/api/v1/staff/collections', ['title_en' => 'X', 'slug' => 'live'])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');                    // the shop uses /collections/live
        $this->admin()->postJson('/api/v1/staff/collections', ['title_en' => 'X', 'slug' => 'rain'])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');                    // taken
        $this->admin()->postJson('/api/v1/staff/collections', ['title_en' => 'X', 'books' => [999999]])
            ->assertUnprocessable()->assertJsonValidationErrors('books');
        $this->admin()->patchJson("/api/v1/staff/collections/{$collection->id}", ['starts_at' => '2030-02-01', 'ends_at' => '2030-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    public function test_staff_view_only_customers_none_and_admin_deletes(): void
    {
        $collection = $this->collection(['slug' => 'rain'], [$this->book('Alpha')]);
        $staff = $this->staffToken();

        $this->asToken($staff)->getJson('/api/v1/staff/collections')->assertOk()->assertJsonPath('data.0.slug', 'rain');
        $this->asToken($staff)->patchJson("/api/v1/staff/collections/{$collection->id}", ['is_active' => false])->assertForbidden();
        $this->asToken($staff)->deleteJson("/api/v1/staff/collections/{$collection->id}")->assertForbidden();
        $customer = Customer::factory()->create();
        $this->asToken($customer->createToken('t', ['customer'])->plainTextToken)->getJson('/api/v1/staff/collections')->assertForbidden();

        $this->admin()->deleteJson("/api/v1/staff/collections/{$collection->id}")->assertNoContent();
        $this->assertSame(0, DB::table('collection_books')->count());
        $this->assertSame(1, AdminAuditLog::where('action', 'book_collection.deleted')->count());
    }
}
