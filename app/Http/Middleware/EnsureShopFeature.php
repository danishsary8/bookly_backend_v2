<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureShopFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(in_array($feature, ['gift_cards', 'referrals'], true) && config('shop.'.$feature.'_enabled'), 404);

        return $next($request);
    }
}
