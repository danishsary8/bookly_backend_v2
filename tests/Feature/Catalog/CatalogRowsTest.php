<?php

namespace Tests\Feature\Catalog;

use App\Enums\BookFormat;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class CatalogRowsTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    public function test_rows_omit_empty_and_cap_books_while_reporting_total(): void
    {
        $this->getJson('/api/v1/catalog/rows')->assertOk()->assertJsonPath('data', []);
        $this->travel(16)->minutes();
        BookVariant::factory()->count(14)->create();
        $this->getJson('/api/v1/catalog/rows')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'new_arrivals')->assertJsonPath('data.0.total', 14)->assertJsonCount(12, 'data.0.books');
    }

    public function test_best_sellers_use_real_recent_non_cancelled_sales_and_category_descendants(): void
    {
        $category = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $category->id]);
        $hit = $this->physical();
        $hit->book->categories()->attach($child);
        $old = $this->physical();
        $customer = Customer::factory()->create();
        $this->deliveredOrder($customer, [[$hit, 3]]);
        $this->deliveredOrder($customer, [[$old, 50]])->update(['placed_at' => now()->subDays(31)]);
        $this->deliveredOrder($customer, [[$old, 50]])->update(['status' => 'cancelled']);
        $this->getJson('/api/v1/catalog/rows?category_id='.$category->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'best_sellers')
            ->assertJsonPath('data.0.total', 1)->assertJsonPath('data.0.books.0.id', $hit->book_id);
    }

    public function test_coming_soon_and_deals_use_active_formats_and_correct_order(): void
    {
        $later = BookVariant::factory()->create(['release_date' => today()->addDays(10)]);
        $soon = BookVariant::factory()->create(['release_date' => today()->addDays(2)]);
        $cheap = BookVariant::factory()->create(['format' => BookFormat::Ebook, 'price_usd' => '1.00']);
        $deal = BookVariant::factory()->create(['format' => BookFormat::Ebook, 'price_usd' => '4.99']);
        BookVariant::factory()->create(['format' => BookFormat::Ebook, 'price_usd' => '5.00']);
        BookVariant::factory()->create(['format' => BookFormat::Ebook, 'price_usd' => '0.00', 'is_active' => false]);
        $rows = collect($this->getJson('/api/v1/catalog/rows')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame([$soon->book_id, $later->book_id], array_column($rows['coming_soon']['books'], 'id'));
        $this->assertSame([$cheap->book_id, $deal->book_id], array_column($rows['ebook_deals']['books'], 'id'));
        $this->getJson('/api/v1/books?coming_soon=1&sort=release_soon')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $soon->book_id);
    }

    public function test_cached_rows_recheck_visibility_and_expire_after_fifteen_minutes(): void
    {
        $variant = $this->physical();
        $this->getJson('/api/v1/catalog/rows')->assertOk()->assertJsonPath('data.0.total', 1);
        Book::whereKey($variant->book_id)->update(['status' => 'draft']);
        $this->getJson('/api/v1/catalog/rows')->assertOk()->assertJsonPath('data', []);
        $this->physical();
        $this->travel(16)->minutes();
        $this->getJson('/api/v1/catalog/rows')->assertOk()->assertJsonPath('data.0.total', 1);
    }
}
