<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof Customer || ! $user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Please verify your email address first.'], 403);
        }

        return $next($request);
    }
}
