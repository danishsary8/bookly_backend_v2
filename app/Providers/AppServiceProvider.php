<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
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
    }
}
