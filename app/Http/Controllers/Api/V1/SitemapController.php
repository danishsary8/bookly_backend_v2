<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Book;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\Series;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * Generate dynamic sitemap.xml for search engines.
     */
    public function sitemap(Request $request): Response
    {
        $baseUrl = rtrim((string) (config('shop.url') ?: config('app.url') ?: $request->schemeAndHttpHost()), '/');

        $staticRoutes = [
            ['path' => '/', 'priority' => '1.0', 'changefreq' => 'daily'],
            ['path' => '/books', 'priority' => '0.9', 'changefreq' => 'daily'],
            ['path' => '/authors', 'priority' => '0.7', 'changefreq' => 'weekly'],
            ['path' => '/series', 'priority' => '0.7', 'changefreq' => 'weekly'],
            ['path' => '/about', 'priority' => '0.5', 'changefreq' => 'monthly'],
            ['path' => '/contact', 'priority' => '0.5', 'changefreq' => 'monthly'],
            ['path' => '/shipping', 'priority' => '0.6', 'changefreq' => 'monthly'],
            ['path' => '/returns-policy', 'priority' => '0.6', 'changefreq' => 'monthly'],
            ['path' => '/terms', 'priority' => '0.4', 'changefreq' => 'monthly'],
            ['path' => '/privacy', 'priority' => '0.4', 'changefreq' => 'monthly'],
            ['path' => '/faq', 'priority' => '0.5', 'changefreq' => 'monthly'],
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($staticRoutes as $route) {
            $loc = htmlspecialchars("{$baseUrl}{$route['path']}", ENT_XML1, 'UTF-8');
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$loc}</loc>\n";
            $xml .= "    <changefreq>{$route['changefreq']}</changefreq>\n";
            $xml .= "    <priority>{$route['priority']}</priority>\n";
            $xml .= "  </url>\n";
        }

        // Visible books (live or scheduled past publish_at, with active variants)
        $books = Book::visible()->select(['id', 'updated_at'])->get();
        foreach ($books as $book) {
            $lastmod = $book->updated_at ? $book->updated_at->toIso8601String() : date('c');
            $loc = htmlspecialchars("{$baseUrl}/books/{$book->id}", ENT_XML1, 'UTF-8');
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$loc}</loc>\n";
            $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
            $xml .= "    <changefreq>weekly</changefreq>\n";
            $xml .= "    <priority>0.8</priority>\n";
            $xml .= "  </url>\n";
        }

        // Categories that have visible books
        $categories = Category::whereHas('books', fn ($q) => $q->visible())
            ->select(['id', 'slug', 'updated_at'])
            ->get();
        foreach ($categories as $cat) {
            $slug = rawurlencode($cat->slug);
            $lastmod = $cat->updated_at ? $cat->updated_at->toIso8601String() : date('c');
            $loc = htmlspecialchars("{$baseUrl}/categories/{$slug}", ENT_XML1, 'UTF-8');
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$loc}</loc>\n";
            $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
            $xml .= "    <changefreq>weekly</changefreq>\n";
            $xml .= "    <priority>0.7</priority>\n";
            $xml .= "  </url>\n";
        }

        // Authors who have visible books
        $authors = Author::whereHas('books', fn ($q) => $q->visible())
            ->select(['id', 'updated_at'])
            ->get();
        foreach ($authors as $author) {
            $lastmod = $author->updated_at ? $author->updated_at->toIso8601String() : date('c');
            $loc = htmlspecialchars("{$baseUrl}/authors/{$author->id}", ENT_XML1, 'UTF-8');
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$loc}</loc>\n";
            $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
            $xml .= "    <changefreq>weekly</changefreq>\n";
            $xml .= "    <priority>0.6</priority>\n";
            $xml .= "  </url>\n";
        }

        // Series with visible books
        $series = Series::whereHas('books', fn ($q) => $q->visible())
            ->select(['id', 'updated_at'])
            ->get();
        foreach ($series as $item) {
            $lastmod = $item->updated_at ? $item->updated_at->toIso8601String() : date('c');
            $loc = htmlspecialchars("{$baseUrl}/series/{$item->id}", ENT_XML1, 'UTF-8');
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$loc}</loc>\n";
            $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
            $xml .= "    <changefreq>weekly</changefreq>\n";
            $xml .= "    <priority>0.6</priority>\n";
            $xml .= "  </url>\n";
        }

        // Publishers with visible books
        $publishers = Publisher::whereHas('books', fn ($q) => $q->visible())
            ->select(['id', 'updated_at'])
            ->get();
        foreach ($publishers as $pub) {
            $lastmod = $pub->updated_at ? $pub->updated_at->toIso8601String() : date('c');
            $loc = htmlspecialchars("{$baseUrl}/publishers/{$pub->id}", ENT_XML1, 'UTF-8');
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$loc}</loc>\n";
            $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
            $xml .= "    <changefreq>monthly</changefreq>\n";
            $xml .= "    <priority>0.5</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Generate robots.txt file.
     */
    public function robots(Request $request): Response
    {
        $baseUrl = rtrim((string) (config('shop.url') ?: config('app.url') ?: $request->schemeAndHttpHost()), '/');

        $content = "User-agent: *\n";
        $content .= "Allow: /\n";
        $content .= "Disallow: /admin\n";
        $content .= "Disallow: /admin/\n";
        $content .= "Disallow: /account\n";
        $content .= "Disallow: /account/\n";
        $content .= "Disallow: /checkout\n";
        $content .= "Disallow: /checkout/\n";
        $content .= "Disallow: /verify-email\n";
        $content .= "Disallow: /reset-password\n";
        $content .= "Disallow: /forgot-password\n";
        $content .= "Disallow: /newsletter/\n\n";
        $content .= "Sitemap: {$baseUrl}/sitemap.xml\n";

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
