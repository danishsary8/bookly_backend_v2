<?php

namespace App\Providers;

use App\Services\CurrencyService;
use App\Services\KhrRateHistory;
use App\Services\Telegram\TelegramBot;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request so the exchange rate is read once, not per book.
        $this->app->scoped(CurrencyService::class);
        $this->app->scoped(KhrRateHistory::class);
    }

    public function boot(): void
    {
        // Real visitor IP + HTTPS detection behind the host's load balancer (config/app.php, TRUSTED_PROXIES).
        $proxies = trim((string) config('app.trusted_proxies'));
        TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));

        // Every API route: 120 requests per minute per logged-in user, or per IP for guests. Telegram's own calls to the
        // bot webhook (with the right secret header) are not limited: many customers tapping the bot at once must not
        // make Telegram retry. Calls without the secret are limited like any other request (owner, 2026-10-13).
        RateLimiter::for('api', function (Request $request) {
            if ($request->is('api/v1/telegram/webhook') && TelegramBot::enabled()
                && hash_equals(app(TelegramBot::class)->webhookSecret(), (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
                return Limit::none();
            }

            return Limit::perMinute(120)->by($request->user() ? class_basename($request->user()).':'.$request->user()->getKey() : 'ip:'.$request->ip());
        });

        // Login, OTP and reset endpoints: 5 attempts per minute per email (or user) + IP,
        // plus 30 per minute per IP so rotating through many emails does not bypass the limit.
        RateLimiter::for('auth', function (Request $request) {
            $who = strtolower((string) $request->input('email')) ?: ($request->user()?->getKey() ?? 'guest');

            return [
                Limit::perMinute(5)->by($who.'|'.$request->ip()),
                Limit::perMinute(30)->by('ip|'.$request->ip()),
            ];
        });

        // Sending new codes: 3 per 10 minutes so the endpoint cannot be used to spam inboxes.
        RateLimiter::for('otp-send', function (Request $request) {
            $who = strtolower((string) $request->input('email')) ?: ($request->user()?->getKey() ?? 'guest');

            return Limit::perMinutes(10, 3)->by('otp|'.$who.'|'.$request->ip());
        });

        // New Telegram bot links: free to make, but each one is a cache entry. 10 per 10 minutes is plenty.
        RateLimiter::for('telegram-link', fn (Request $request) => Limit::perMinutes(10, 10)
            ->by('telegram|'.($request->user()?->getKey() ?? 'guest').'|'.$request->ip()));

        // Limits chosen by the owner on 2026-10-13 ("looser" set, docs/RATE_LIMITS.md). Logged-in routes count per
        // customer or staff member, so people sharing a mobile network IP (CGNAT in Cambodia) don't block each other.
        $who = fn (Request $request): string => $request->user() ? class_basename($request->user()).':'.$request->user()->getKey() : 'ip:'.$request->ip();

        // Placing orders holds stock (cash on delivery), so a burst of orders could empty the shelves.
        RateLimiter::for('checkout', fn (Request $request) => [
            Limit::perMinutes(5, 5)->by('checkout-5m|'.$who($request)),
            Limit::perHour(20)->by('checkout-1h|'.$who($request)),
        ]);

        // Trying coupon codes. Wrong codes in a row also lock checking for 15 minutes (CouponCheckController).
        RateLimiter::for('coupon-check', fn (Request $request) => Limit::perMinute(10)->by('coupon|'.$who($request)));

        // The website asks every 2 seconds (30 a minute) while the customer is in the bot; 60 leaves room.
        RateLimiter::for('telegram-status', fn (Request $request) => Limit::perMinute(60)
            ->by('telegram-status|'.$request->ip().'|'.substr((string) $request->input('key'), 0, 64)));

        // Public book list: text search costs the database more than browsing.
        RateLimiter::for('catalog', fn (Request $request) => $request->filled('q')
            ? Limit::perMinute(60)->by('catalog-search|'.$request->ip())
            : Limit::perMinute(120)->by('catalog-browse|'.$request->ip()));

        // Dashboard numbers run several aggregate queries each time.
        RateLimiter::for('admin-analytics', fn (Request $request) => Limit::perMinute(30)->by('analytics|'.$who($request)));
    }
}
