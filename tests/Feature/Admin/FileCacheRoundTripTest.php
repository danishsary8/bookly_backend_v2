<?php

namespace Tests\Feature\Admin;

use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * Production caches on files, and config/cache.php refuses to unserialize objects (`serializable_classes` false). A
 * cached Collection or model then comes back as __PHP_Incomplete_Class on the second request (found 2026-11-01 on the
 * dashboard's orders by status). Every cached endpoint must answer the same JSON the second time, from the file cache.
 */
class FileCacheRoundTripTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    public function test_cached_admin_and_catalog_answers_survive_the_file_cache(): void
    {
        $this->seed(DemoSeeder::class);
        config(['cache.default' => 'file']);
        Cache::flush();
        $admin = $this->staffToken(admin: true);
        $range = 'from='.now()->subDays(40)->toDateString().'&to='.now()->toDateString();

        $urls = [
            '/api/v1/staff/dashboard/summary?period=30d',
            '/api/v1/staff/dashboard/sales?period=30d',
            "/api/v1/staff/reports/sales?{$range}",
            "/api/v1/staff/reports/orders?{$range}",
            "/api/v1/staff/reports/products?{$range}",
            "/api/v1/staff/reports/customers?{$range}",
            "/api/v1/staff/reports/funnel?{$range}",
            "/api/v1/staff/reports/coupons?{$range}",
            '/api/v1/books/5/related?limit=12',
            '/api/v1/books/5',
            '/api/v1/books',
        ];

        foreach ($urls as $url) {
            $first = $this->asToken($admin)->getJson($url)->assertOk()->getContent();
            $second = $this->asToken($admin)->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('__PHP_Incomplete_Class', $second, $url);
            $this->assertSame(json_decode($first, true), json_decode($second, true), "{$url} changed when read back from the file cache");
        }
        Cache::flush();
    }
}
