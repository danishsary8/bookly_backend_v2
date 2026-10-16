<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\Concerns\ConfirmsIdentity;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Notifications\AccountSecurityNotice;
use App\Services\Auth\SocialTokenVerifier;
use App\Services\Customers\ClosedCustomers;
use App\Support\SafeNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class ProfileController extends Controller
{
    use ConfirmsIdentity;

    public function show(Request $request): CustomerResource
    {
        return new CustomerResource($request->user());
    }

    public function update(Request $request): CustomerResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'locale' => ['sometimes', 'required', 'string', Rule::in(['en', 'km'])],
            'telegram_order_updates' => ['sometimes', 'boolean'],
        ]);

        $request->user()->update($data);

        return new CustomerResource($request->user());
    }

    public function disconnectTelegram(Request $request): CustomerResource
    {
        $customer = $request->user();
        $customer->update([
            'telegram_chat_id' => null,
            'telegram_order_updates' => false,
        ]);

        return new CustomerResource($customer);
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
        // A first password is a new way in: a recent sign-in proves it's the owner, not a stolen session.
        if ($customer->password_hash === null && ($problem = $this->identityProblem($customer, null))) {
            return $problem;
        }

        $customer->update(['password_hash' => $data['password']]);
        $customer->tokens()->where('id', '!=', $customer->currentAccessToken()->id)->delete();
        // So the owner of the account notices a change they didn't make. Never blocks the change.
        SafeNotifier::toCustomer($customer, new AccountSecurityNotice('Your Bookly password was changed', 'The password of your Bookly account was just changed, and other devices were signed out.', 'password_changed'));

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
        $hasPassword = $customer->password_hash !== null;
        $needsSocial = ! $hasPassword && $customer->hasVerifiedEmail();

        $rules = [
            'confirm' => ['required', Rule::in(['DELETE'])],
        ];

        if ($hasPassword) {
            $rules['password'] = ['required', 'string'];
        } elseif ($needsSocial) {
            $rules['provider'] = ['required', Rule::in(['google', 'facebook'])];
            $rules['access_token'] = ['required', 'string', 'max:4096'];
        } else {
            $rules['password'] = ['nullable', 'string'];
            $rules['provider'] = ['nullable', 'string'];
            $rules['access_token'] = ['nullable', 'string'];
        }

        $data = $request->validate($rules, [
            'confirm.in' => 'Type DELETE to confirm.',
            'confirm.required' => 'Type DELETE to confirm.',
        ]);

        if ($hasPassword) {
            if (! Hash::check($data['password'], $customer->password_hash)) {
                return response()->json(['message' => 'That password is incorrect.', 'errors' => ['password' => ['That password is incorrect.']]], 422);
            }
        } elseif ($needsSocial) {
            if (! $this->sameSocialAccount($customer, $data['provider'], $data['access_token'], $verifier)) {
                return response()->json(['message' => 'Sign in with the '.ucfirst($data['provider']).' account linked to Bookly to confirm.'], 422);
            }
        } else {
            if ($problem = $this->identityProblem($customer, null)) {
                return $problem;
            }
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
