<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\Concerns\IssuesTokens;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\Auth\SocialTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * The frontend signs the user in with Google/Facebook and sends us the provider access token;
 * we check the token was issued to our app and fetch the profile from the provider ourselves,
 * so the client cannot fake an identity.
 */
class SocialAuthController extends Controller
{
    use IssuesTokens;

    private const COLUMNS = ['google' => 'google_id', 'facebook' => 'facebook_id'];

    public function __invoke(Request $request, string $provider, SocialTokenVerifier $verifier): JsonResponse
    {
        abort_unless(isset(self::COLUMNS[$provider]), 404);
        $data = $request->validate(['access_token' => ['required', 'string', 'max:4096']]);

        if (! $verifier->issuedToUs($provider, $data['access_token'])) {
            return response()->json(['message' => 'Could not verify your '.ucfirst($provider).' account.'], 401);
        }

        try {
            $profile = Socialite::driver($provider)->stateless()->userFromToken($data['access_token']);
        } catch (Throwable) {
            return response()->json(['message' => 'Could not verify your '.ucfirst($provider).' account.'], 401);
        }

        $email = $profile->getEmail() ? strtolower($profile->getEmail()) : null;
        if ($email === null) {
            return response()->json(['message' => 'Your '.ucfirst($provider).' account has no email address. Please register with email instead.'], 422);
        }

        // Google tells us whether it checked the address; we only trust (and link by) checked addresses.
        if ($provider === 'google' && ! filter_var($profile->getRaw()['email_verified'] ?? false, FILTER_VALIDATE_BOOL)) {
            return response()->json(['message' => 'Please verify your email address with Google first, or register with email instead.'], 422);
        }

        $column = self::COLUMNS[$provider];
        $customer = Customer::where($column, $profile->getId())->first()
            ?? Customer::where('email', $email)->first();

        if ($customer !== null && ! $customer->is_active) {
            return response()->json(['message' => 'This account has been deactivated. Please contact support.'], 403);
        }

        // Someone may have registered this email without owning it and never verified it. The real owner
        // has now proven the address, so the unproven password and its sessions must stop working.
        if ($customer !== null && $customer->email_verified_at === null) {
            $customer->forceFill(['password_hash' => null])->save();
            $customer->tokens()->delete();
        }

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
