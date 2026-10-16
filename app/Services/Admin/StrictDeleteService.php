<?php

namespace App\Services\Admin;

use App\Models\StaffUser;
use App\Services\Auth\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class StrictDeleteService
{
    public function __construct(
        private readonly TotpService $totp,
    ) {}

    /**
     * Validates confirmation text, password, and fresh 2FA code.
     * Returns a JsonResponse on validation error/lockout, or null if verification passed.
     */
    public function verify(
        StaffUser $admin,
        string $confirm,
        string $expectedConfirm,
        string $password,
        string $code,
    ): ?JsonResponse {
        // Confirmation check first (costs nothing, doesn't burn 2FA code or increment wrong-code limiter)
        if ($confirm !== $expectedConfirm) {
            return response()->json([
                'message' => "Type \"{$expectedConfirm}\" to confirm.",
                'errors' => ['confirm' => ["Type \"{$expectedConfirm}\" to confirm."]],
            ], 422);
        }

        // Password check
        if (! Hash::check($password, $admin->password_hash)) {
            return response()->json([
                'message' => 'That password is incorrect.',
                'errors' => ['password' => ['That password is incorrect.']],
            ], 422);
        }

        // 2FA rate limiter: 5 attempts per 15 minutes across any IP
        $limiter = "staff-2fa-wrong:{$admin->id}";
        if (RateLimiter::tooManyAttempts($limiter, 5)) {
            return response()->json(['message' => 'Too many invalid codes. Please try again later.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($limiter));
        }

        // Fresh TOTP code verification with replay cache prevention
        if (! $this->verifyFreshTotpCode($admin, $code)) {
            RateLimiter::hit($limiter, 900);

            return response()->json([
                'message' => 'Invalid authentication code.',
                'errors' => ['code' => ['Invalid authentication code.']],
            ], 422);
        }

        RateLimiter::clear($limiter);

        return null;
    }

    private function verifyFreshTotpCode(StaffUser $admin, string $code): bool
    {
        if (! $admin->two_factor_secret) {
            return false;
        }

        $cacheKey = "used-totp:staff:{$admin->id}:{$code}";
        if (Cache::has($cacheKey)) {
            return false;
        }

        if (! $this->totp->verify((string) $admin->two_factor_secret, $code)) {
            return false;
        }

        Cache::put($cacheKey, true, 120);

        return true;
    }
}
