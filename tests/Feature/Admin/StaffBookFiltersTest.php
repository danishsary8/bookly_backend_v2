<?php

namespace Tests\Feature\Admin;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/** Finding a book in the staff list (owner 2026-11-14): search by title, author or ISBN; filter by shelf, format and stock. */
class StaffBookFiltersTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    public function test_search_and_filters_narrow_the_list(): void
    {
        $fiction = Category::factory()->create(['name' => 'Fiction']);
        $classics = Category::factory()->create(['name' => 'Classics', 'parent_id' => $fiction->id]);
        $science = Category::factory()->create(['name' => 'Science']);
        $austen = Author::factory()->create(['name' => 'Jane Austen']);

        $emma = Book::factory()->create(['title' => 'Emma']);
        $emma->authors()->sync([$austen->id]);
        $emma->categories()->sync([$classics->id]);
        BookVariant::factory()->create(['book_id' => $emma->id, 'format' => 'paperback', 'isbn' => '9780141439587', 'stock_quantity' => 2, 'low_stock_threshold' => 5]);

        $cosmos = Book::factory()->create(['title' => 'Cosmos']);
        $cosmos->categories()->sync([$science->id]);
        BookVariant::factory()->create(['book_id' => $cosmos->id, 'format' => 'hardcover', 'isbn' => '9780345539434', 'stock_quantity' => 0]);

        $ebook = Book::factory()->create(['title' => 'Digital Only']);
        BookVariant::factory()->create(['book_id' => $ebook->id, 'format' => 'ebook', 'isbn' => null, 'stock_quantity' => 0]);

        $token = $this->staffToken();
        $titles = fn (string $query) => collect($this->asToken($token)->getJson('/api/v1/staff/books?'.$query)->assertOk()->json('data'))->pluck('title')->sort()->values()->all();

        $this->assertSame(['Emma'], $titles('q=austen'));
        $this->assertSame(['Cosmos'], $titles('q=978-0345'));
        $this->assertSame(['Emma'], $titles('category_id='.$fiction->id)); // a parent shelf includes its sub-shelves
        $this->assertSame(['Digital Only'], $titles('format=ebook'));
        $this->assertSame(['Emma'], $titles('stock=low'));
        $this->assertSame(['Cosmos'], $titles('stock=out')); // ebooks are never "out of stock"
        $this->assertSame(['Emma'], $titles('stock=in'));
        $this->assertSame(['Emma'], $titles('author_id='.$austen->id));
        $this->assertSame(['Cosmos', 'Digital Only', 'Emma'], collect($this->asToken($token)->getJson('/api/v1/staff/books?sort=title')->json('data'))->pluck('title')->all());
        $this->asToken($token)->getJson('/api/v1/staff/books?stock=many')->assertJsonValidationErrors('stock');
    }
}
