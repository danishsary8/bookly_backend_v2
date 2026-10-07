<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Services\Auth\SocialTokenVerifier;
use App\Services\Customers\ClosedCustomers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class ProfileController extends Controller
{
    public function show(Request $request): CustomerResource
    {
        return new CustomerResource($request->user());
    }

    public function update(Request $request): CustomerResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        $request->user()->update($data);

        return new CustomerResource($request->user());
    }

    public function changePassword(Request $request): JsonResponse
    {
        $customer = $request->user();
        $data = $request->validate([
            'current_password' => [$customer->password_hash === null ? 'nullable' : 'required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if ($customer->password_hash !== null && ! Hash::check($data['current_password'] ?? '', $customer->password_hash)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $customer->update(['password_hash' => $data['password']]);
        $customer->tokens()->where('id', '!=', $customer->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password changed. Other sessions were signed out.']);
    }

    /**
     * Closes the customer's own account. They prove it's them again: their password, or (accounts made with
     * Google/Facebook and no password) a fresh sign-in with that provider. Open orders or returns must
     * finish first. Personal details are erased after ClosedCustomers::days().
     */
    public function destroy(Request $request, ClosedCustomers $closed, SocialTokenVerifier $verifier): JsonResponse
    {
        $customer = $request->user();
        $data = $request->validate([
            'confirm' => ['required', Rule::in(['DELETE'])],
            'password' => [$customer->password_hash !== null ? 'required' : 'nullable', 'string'],
            'provider' => [$customer->password_hash === null ? 'required' : 'nullable', Rule::in(['google', 'facebook'])],
            'access_token' => [$customer->password_hash === null ? 'required' : 'nullable', 'string', 'max:4096'],
        ], ['confirm.in' => 'Type DELETE to confirm.', 'confirm.required' => 'Type DELETE to confirm.']);

        if ($customer->password_hash !== null) {
            if (! Hash::check($data['password'], $customer->password_hash)) {
                return response()->json(['message' => 'That password is incorrect.', 'errors' => ['password' => ['That password is incorrect.']]], 422);
            }
        } elseif (! $this->sameSocialAccount($customer, $data['provider'], $data['access_token'], $verifier)) {
            return response()->json(['message' => 'Sign in with the '.ucfirst($data['provider']).' account linked to Bookly to confirm.'], 422);
        }

        if ($blocker = $closed->blocker($customer)) {
            return response()->json(['message' => $blocker], 422);
        }

        $eraseAt = $closed->close($customer);

        return response()->json([
            'message' => 'Your account is closed. We erase your personal details on '.$eraseAt->toFormattedDayDateString().'.',
            'erase_at' => $eraseAt,
        ]);
    }

    private function sameSocialAccount($customer, string $provider, string $token, SocialTokenVerifier $verifier): bool
    {
        $id = $customer->{$provider.'_id'};
        if ($id === null || ! $verifier->issuedToUs($provider, $token)) {
            return false;
        }
        try {
            return (string) Socialite::driver($provider)->stateless()->userFromToken($token)->getId() === (string) $id;
        } catch (Throwable) {
            return false;
        }
    }
}
