<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Notifications\AccountSecurityNotice;
use App\Services\Auth\SocialTokenVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Account → Sign-in & security: connect or disconnect Google and Facebook.
 *
 * Connecting proves both sides: the provider token must be issued to our app (we read the profile from the
 * provider ourselves), and accounts with a password type it again, so a stolen session can't quietly add
 * the thief's own Google. A provider account already linked to another Bookly account is refused.
 * Disconnecting never removes the last way in ("Add a password first").
 */
class ConnectionController extends Controller
{
    private const COLUMNS = ['google' => 'google_id', 'facebook' => 'facebook_id'];

    public const LAST_WAY_IN = 'Add a password first, so you can still sign in.';

    public function store(Request $request, string $provider, SocialTokenVerifier $verifier): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $data = $request->validate([
            'access_token' => ['required', 'string', 'max:4096'],
            'password' => [$customer->password_hash !== null ? 'required' : 'nullable', 'string'],
        ]);
        $name = ucfirst($provider);
        $column = self::COLUMNS[$provider];

        if ($customer->password_hash !== null && ! Hash::check($data['password'], $customer->password_hash)) {
            return response()->json(['message' => 'That password is incorrect.', 'errors' => ['password' => ['That password is incorrect.']]], 422);
        }

        $profile = $verifier->profile($provider, $data['access_token']);
        if ($profile === null) {
            // 422, not 401: the customer is signed in, and a 401 would end their session in the app.
            return response()->json(['message' => "Could not verify your {$name} account. Try again."], 422);
        }
        $id = (string) $profile->getId();

        if ($customer->{$column} === $id) {
            return response()->json(['message' => "{$name} is already connected.", 'customer' => new CustomerResource($customer)]);
        }
        if ($customer->{$column} !== null) {
            return response()->json(['message' => "Another {$name} account is connected. Disconnect it first."], 409);
        }
        if (Customer::withTrashed()->where($column, $id)->exists()) {
            return response()->json(['message' => "This {$name} account is already used by another Bookly account."], 409);
        }

        try {
            $customer->forceFill([$column => $id])->save();
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => "This {$name} account is already used by another Bookly account."], 409);
        }
        $this->notice($customer, "{$name} was connected to your Bookly account", "You can now sign in to Bookly with {$name}.");

        return response()->json(['message' => "{$name} connected. You can now sign in with it.", 'customer' => new CustomerResource($customer)]);
    }

    public function destroy(Request $request, string $provider): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $name = ucfirst($provider);
        $column = self::COLUMNS[$provider];

        if ($customer->{$column} === null) {
            return response()->json(['message' => "{$name} is not connected.", 'customer' => new CustomerResource($customer)]);
        }
        if ($customer->signInMethods() === [$provider]) {
            return response()->json(['message' => self::LAST_WAY_IN, 'reason' => 'last_way_in'], 422);
        }

        $customer->forceFill([$column => null])->save();
        $this->notice($customer, "{$name} was disconnected from your Bookly account", "{$name} can no longer be used to sign in to Bookly.");

        return response()->json(['message' => "{$name} disconnected.", 'customer' => new CustomerResource($customer)]);
    }

    private function notice(Customer $customer, string $subject, string $what): void
    {
        if ($customer->email !== null && $customer->hasVerifiedEmail()) {
            $customer->notify(new AccountSecurityNotice($subject, $what));
        }
    }
}
