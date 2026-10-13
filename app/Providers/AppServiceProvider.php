<?php

namespace App\Providers;

use App\Services\CurrencyService;
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
    }

    public function boot(): void
    {
        // Real visitor IP + HTTPS detection behind the host's load balancer (config/app.php, TRUSTED_PROXIES).
        $proxies = trim((string) config('app.trusted_proxies'));
        TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));

        // Every API route: 120 requests per minute per logged-in user, or per IP for guests.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user() ? class_basename($request->user()).':'.$request->user()->getKey() : 'ip:'.$request->ip()));

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
    }
}
