<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\Concerns\IssuesTokens;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * The frontend signs the user in with Google/Facebook and sends us the provider access token;
 * we fetch the profile from the provider ourselves, so the client cannot fake an identity.
 */
class SocialAuthController extends Controller
{
    use IssuesTokens;

    private const COLUMNS = ['google' => 'google_id', 'facebook' => 'facebook_id'];

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        abort_unless(isset(self::COLUMNS[$provider]), 404);
        $data = $request->validate(['access_token' => ['required', 'string']]);

        try {
            $profile = Socialite::driver($provider)->stateless()->userFromToken($data['access_token']);
        } catch (Throwable) {
            return response()->json(['message' => 'Could not verify your '.ucfirst($provider).' account.'], 401);
        }

        $email = $profile->getEmail() ? strtolower($profile->getEmail()) : null;
        if ($email === null) {
            return response()->json(['message' => 'Your '.ucfirst($provider).' account has no email address. Please register with email instead.'], 422);
        }

        $column = self::COLUMNS[$provider];
        $customer = Customer::where($column, $profile->getId())->first()
            ?? Customer::where('email', $email)->first();

        $created = $customer === null;
        $customer ??= new Customer(['email' => $email, 'name' => $profile->getName() ?: $email]);
        $customer->forceFill([
            $column => $profile->getId(),
            'email_verified_at' => $customer->email_verified_at ?? now(),
        ])->save();

        return response()->json([
            'customer' => new CustomerResource($customer),
            ...$this->issueToken($customer, ['customer']),
        ], $created ? 201 : 200);
    }
}
