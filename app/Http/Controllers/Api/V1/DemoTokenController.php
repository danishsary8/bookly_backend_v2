<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\JsonResponse;

/**
 * One-click sign-in for the /docs page: a short-lived customer token for the shared demo account only.
 * It never takes a password or an email, so it can't be used to reach any other account; real sign-ins keep their
 * bot check (Turnstile). The demo account itself can't change its sign-in details (not.demo) and is tidied hourly.
 */
class DemoTokenController extends Controller
{
    public const HOURS = 2;

    public function __invoke(): JsonResponse
    {
        $demo = Customer::where('email', DemoSeeder::EMAIL)->where('is_active', true)->first();
        abort_if($demo === null, 404, 'The demo account is not set up on this server.');

        $expiresAt = now()->addHours(self::HOURS);
        $token = $demo->createToken('docs-demo', ['customer'], $expiresAt);

        return response()->json([
            'customer' => new CustomerResource($demo),
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }
}
