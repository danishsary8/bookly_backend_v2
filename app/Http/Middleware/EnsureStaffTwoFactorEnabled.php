<?php

namespace App\Http\Middleware;

use App\Models\StaffUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof StaffUser) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! $user->two_factor_enabled) {
            return response()->json([
                'message' => 'Two-factor authentication must be enabled before using staff features.',
                'two_factor_setup_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
