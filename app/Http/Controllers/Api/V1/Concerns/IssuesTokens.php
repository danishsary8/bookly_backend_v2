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

        $name = $tokenName ?? ($user instanceof StaffUser ? DeviceDetector::detect(request()->userAgent()) : 'api');
        $token = $user->createToken($name, $abilities, $expiresAt);

        if ($user instanceof StaffUser && request()->ip()) {
            $token->accessToken->forceFill(['ip_address' => request()->ip()])->save();
        }

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }
}
