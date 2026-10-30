<?php

namespace App\Http\Middleware;

use Closure;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The docs page publishes the shared demo customer's password, so nobody may change how that account signs in
 * (password, email, linked logins) or delete it: the next visitor would be locked out.
 */
class EnsureNotDemoAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->email === DemoSeeder::EMAIL) {
            return response()->json(['message' => "The shared demo account can't change this. Create your own account to try it."], 403);
        }

        return $next($request);
    }
}
