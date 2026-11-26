<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Dashboard numbers are cached for a few minutes, but a new order, payment, status change or return must show on the
 * next refresh (owner 2026-11-26: "KPIs must show new data when a new action comes"). Every cache key carries a
 * version; order-side changes bump it (AppServiceProvider), so old entries are simply never read again and expire.
 */
final class DashboardCache
{
    private const VERSION_KEY = 'dashboard:version';

    /** "dashboard:summary:…" → "dashboard:v12:summary:…" */
    public static function key(string $key): string
    {
        $version = (int) Cache::get(self::VERSION_KEY, 0);

        return preg_replace('/^dashboard:/', "dashboard:v{$version}:", $key, 1) ?? $key;
    }

    public static function bump(): void
    {
        if (! Cache::add(self::VERSION_KEY, 1)) {
            Cache::increment(self::VERSION_KEY);
        }
    }
}
