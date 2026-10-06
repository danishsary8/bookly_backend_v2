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
        $customer = Customer::where($column, $profile->getId())->first();
        $sameEmail = $customer === null ? Customer::withTrashed()->where('email', $email)->first() : null;

        $account = $customer ?? ($sameEmail?->trashed() ? null : $sameEmail);
        if ($account !== null && ! $account->is_active) {
            return response()->json(['message' => 'This account has been deactivated. Please contact support.'], 403);
        }

        if ($sameEmail !== null) {
            // Owner's rule: Google / Facebook never sign in to an email that already has a real account;
            // that account keeps signing in the way it was set up.
            if ($sameEmail->trashed() || $sameEmail->email_verified_at !== null) {
                return response()->json(['message' => $this->signInAsBefore($sameEmail)], 409);
            }
            // An unfinished sign-up (never verified) isn't anyone's account yet: the provider has now proven
            // who owns the address, so it becomes theirs and the unproven password and sessions stop working.
            $sameEmail->tokens()->delete();
            $sameEmail->forceFill(['password_hash' => null, 'name' => $profile->getName() ?: $sameEmail->name])->save();
            $customer = $sameEmail;
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

    /** "This email already has a Bookly account. Sign in with your email and password instead." */
    private function signInAsBefore(Customer $existing): string
    {
        if ($existing->trashed()) {
            return 'This email belongs to a closed Bookly account. Please contact us.';
        }
        $ways = array_values(array_filter([
            $existing->password_hash !== null ? 'your email and password' : null,
            $existing->google_id !== null ? 'Google' : null,
            $existing->facebook_id !== null ? 'Facebook' : null,
        ]));
        $how = match (count($ways)) {
            0 => 'the way you signed up',
            1 => $ways[0],
            default => implode(', ', array_slice($ways, 0, -1)).' or '.end($ways),
        };

        return "This email already has a Bookly account. Sign in with {$how} instead.";
    }
}
