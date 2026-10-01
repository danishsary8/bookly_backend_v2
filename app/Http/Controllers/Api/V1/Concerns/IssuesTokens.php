<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\StaffUser;
use Illuminate\Foundation\Auth\User as Authenticatable;

trait IssuesTokens
{
    protected function issueToken(Authenticatable $user, array $abilities): array
    {
        $minutes = $user instanceof StaffUser
            ? config('auth.staff_token_hours') * 60
            : config('sanctum.expiration');
        $expiresAt = $minutes ? now()->addMinutes((int) $minutes) : null;
        $token = $user->createToken('api', $abilities, $expiresAt);

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }
}
