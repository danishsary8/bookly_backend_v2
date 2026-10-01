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
        // Login, OTP and reset endpoints: 5 attempts per minute per email (or user) + IP.
        RateLimiter::for('auth', function (Request $request) {
            $who = strtolower((string) $request->input('email')) ?: ($request->user()?->getKey() ?? 'guest');

            return Limit::perMinute(5)->by($who.'|'.$request->ip());
        });

        // Sending new codes: 3 per 10 minutes so the endpoint cannot be used to spam inboxes.
        RateLimiter::for('otp-send', function (Request $request) {
            $who = strtolower((string) $request->input('email')) ?: ($request->user()?->getKey() ?? 'guest');

            return Limit::perMinutes(10, 3)->by('otp|'.$who.'|'.$request->ip());
        });
    }
}
