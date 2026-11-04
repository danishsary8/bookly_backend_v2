<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Account → Sign-in & security → "Where you're signed in" (owner's choice 2026-11-04): every device that is signed
 * in to the account (one sign-in token each), with buttons to sign one out or all the others. The shared demo account
 * only ever sees its own session: other visitors' devices and IP addresses are none of a visitor's business, and
 * signing them out is blocked (`not.demo` on the routes).
 */
class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();
        $current = $customer->currentAccessToken();
        $tokens = $customer->tokens()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($customer->email === DemoSeeder::EMAIL, fn ($q) => $q->whereKey($current?->id))
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $tokens->map(fn ($token) => [
            'id' => $token->id,
            // Sign-ins before 2026-11-04 were saved without a device name.
            'device' => in_array($token->name, ['api', 'docs-demo'], true) ? null : $token->name,
            'ip_address' => $token->ip_address,
            'signed_in_at' => $token->created_at?->toISOString(),
            'last_used_at' => $token->last_used_at?->toISOString(),
            'expires_at' => $token->expires_at?->toISOString(),
            'is_current' => $token->id === $current?->id,
        ])->values()]);
    }

    /** Signs out one device. Signing out this device is the normal "Sign out" (POST /auth/logout). */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();
        if ($id === $customer->currentAccessToken()?->id) {
            return response()->json(['message' => 'To sign out of this device, use Sign out.'], 422);
        }
        $deleted = $customer->tokens()->whereKey($id)->delete();

        return $deleted
            ? response()->json(['message' => 'Signed out of that device.'])
            : response()->json(['message' => 'That device is already signed out.'], 404);
    }

    /** Signs out every device except this one. */
    public function destroyOthers(Request $request): JsonResponse
    {
        $customer = $request->user();
        $count = $customer->tokens()->whereKeyNot($customer->currentAccessToken()?->id)->delete();

        return response()->json(['message' => 'Signed out of all other devices.', 'signed_out' => $count]);
    }
}
