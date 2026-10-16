<?php

namespace Tests\Feature\Platform;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapAndRobotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_xml_returns_valid_xml_with_static_and_visible_entities(): void
    {
        // 1. Visible book (live, with active variant)
        $visibleBook = Book::create([
            'title' => 'Visible Book',
            'status' => 'live',
        ]);
        BookVariant::create([
            'book_id' => $visibleBook->id,
            'sku' => 'SKU-VIS-001',
            'format' => 'paperback',
            'price_usd' => 15.00,
            'is_active' => true,
            'stock' => 10,
        ]);

        $author = Author::create(['name' => 'Active Author']);
        $visibleBook->authors()->attach($author->id);

        $category = Category::create(['name' => 'Fiction', 'slug' => 'fiction']);
        $visibleBook->categories()->attach($category->id);

        $series = Series::create(['name' => 'Great Saga']);
        $visibleBook->series()->associate($series);
        $visibleBook->save();

        $publisher = Publisher::create(['name' => 'Phnom Penh Press']);
        $visibleBook->publisher()->associate($publisher);
        $visibleBook->save();

        // 2. Draft book (should not be in sitemap)
        $draftBook = Book::create([
            'title' => 'Draft Book',
            'status' => 'draft',
        ]);
        BookVariant::create([
            'book_id' => $draftBook->id,
            'sku' => 'SKU-DFT-001',
            'format' => 'paperback',
            'price_usd' => 12.00,
            'is_active' => true,
            'stock' => 5,
        ]);

        $orphanCategory = Category::create(['name' => 'Empty Cat', 'slug' => 'empty-cat']);

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type') ?? '');

        $content = $response->getContent();
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $content);
        $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $content);

        // Check static pages
        $this->assertStringContainsString('<loc>'.config('app.url').'/</loc>', $content);
        $this->assertStringContainsString('<loc>'.config('app.url').'/books</loc>', $content);
        $this->assertStringContainsString('<loc>'.config('app.url').'/shipping</loc>', $content);
        $this->assertStringContainsString('<loc>'.config('app.url').'/returns-policy</loc>', $content);
        $this->assertStringContainsString('<loc>'.config('app.url').'/terms</loc>', $content);
        $this->assertStringContainsString('<loc>'.config('app.url').'/privacy</loc>', $content);

        // Check dynamic visible items
        $this->assertStringContainsString('/books/'.$visibleBook->id, $content);
        $this->assertStringContainsString('/categories/fiction', $content);
        $this->assertStringContainsString('/authors/'.$author->id, $content);
        $this->assertStringContainsString('/series/'.$series->id, $content);
        $this->assertStringContainsString('/publishers/'.$publisher->id, $content);

        // Assert draft book and empty category are excluded
        $this->assertStringNotContainsString('/books/'.$draftBook->id, $content);
        $this->assertStringNotContainsString('/categories/empty-cat', $content);
    }

    public function test_robots_txt_returns_text_disallowing_sensitive_routes(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type') ?? '');

        $content = $response->getContent();
        $this->assertStringContainsString('User-agent: *', $content);
        $this->assertStringContainsString('Allow: /', $content);
        $this->assertStringContainsString('Disallow: /admin', $content);
        $this->assertStringContainsString('Disallow: /checkout', $content);
        $this->assertStringContainsString('Disallow: /account', $content);
        $this->assertStringContainsString('Sitemap:', $content);
        $this->assertStringContainsString('/sitemap.xml', $content);
    }
}
