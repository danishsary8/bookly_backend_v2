<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\StaffUser;
use App\Services\Staff\DeviceDetector;
use Illuminate\Foundation\Auth\User as Authenticatable;

trait IssuesTokens
{
    protected function issueToken(Authenticatable $user, array $abilities, ?string $tokenName = null): array
    {
        $minutes = $user instanceof StaffUser
            ? config('auth.staff_token_hours') * 60
            : config('sanctum.expiration');
        $expiresAt = $minutes ? now()->addMinutes((int) $minutes) : null;

        // The device ("Chrome on Windows") and IP address: shown under "Where you're signed in" (staff and customers).
        $name = $tokenName ?? DeviceDetector::detect(request()->userAgent());
        $token = $user->createToken($name, $abilities, $expiresAt);

        if (request()->ip()) {
            $token->accessToken->forceFill(['ip_address' => request()->ip()])->save();
        }

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }
}
