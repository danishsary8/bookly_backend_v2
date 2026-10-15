<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A new way into an account (phone, password, Google/Facebook, email) needs proof that the owner is there, not
 * just a stolen session (security review 2026-10-15): the password, or for accounts without one, a sign-in in
 * the last few minutes (Google, Facebook or Telegram just proved who they are).
 */
trait ConfirmsIdentity
{
    public static int $freshSignInMinutes = 10;

    public const SIGN_IN_AGAIN = 'For your safety, sign in again first, then try this within 10 minutes.';

    /** The error to return, or null when the customer has proved it's them. */
    protected function identityProblem(Customer $customer, ?string $password): ?JsonResponse
    {
        if ($customer->password_hash !== null) {
            return Hash::check((string) $password, $customer->password_hash) ? null : response()->json([
                'message' => 'That password is incorrect.',
                'errors' => ['password' => ['That password is incorrect.']],
            ], 422);
        }

        $token = $customer->currentAccessToken();
        if ($token instanceof PersonalAccessToken && $token->created_at?->gt(now()->subMinutes(self::$freshSignInMinutes))) {
            return null;
        }

        return response()->json(['message' => self::SIGN_IN_AGAIN, 'reason' => 'sign_in_again'], 403);
    }
}
