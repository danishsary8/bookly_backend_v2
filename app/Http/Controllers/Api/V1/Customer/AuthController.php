<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\VerificationPurpose;
use App\Http\Controllers\Api\V1\Concerns\IssuesTokens;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    use IssuesTokens;

    public function __construct(private readonly OtpService $otp) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', 'unique:customers,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $customer = Customer::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password_hash' => $data['password'],
            'phone' => $data['phone'] ?? null,
        ]);

        $this->otp->issue($customer, VerificationPurpose::EmailVerify);

        return response()->json([
            'message' => 'Account created. We sent a 6-digit code to your email.',
            'customer' => new CustomerResource($customer),
            ...$this->issueToken($customer, ['customer']),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $customer = Customer::where('email', strtolower($data['email']))->first();

        if ($customer === null || $customer->password_hash === null || ! Hash::check($data['password'], $customer->password_hash)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        return response()->json([
            'customer' => new CustomerResource($customer),
            ...$this->issueToken($customer, ['customer']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $customer = $request->user();

        if ($customer->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email is already verified.']);
        }

        if (! $this->otp->verify($customer, VerificationPurpose::EmailVerify, $data['code'])) {
            return response()->json(['message' => 'The code is invalid or has expired.'], 422);
        }

        $customer->forceFill(['email_verified_at' => now()])->save();

        return response()->json(['message' => 'Email verified.', 'customer' => new CustomerResource($customer)]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $customer = $request->user();

        if (! $customer->hasVerifiedEmail()) {
            $this->otp->issue($customer, VerificationPurpose::EmailVerify);
        }

        return response()->json(['message' => 'If your email is not verified yet, a new code has been sent.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $customer = Customer::where('email', strtolower($data['email']))->first();

        if ($customer !== null) {
            $this->otp->issue($customer, VerificationPurpose::PasswordReset);
        }

        // Same response either way so the endpoint does not reveal which emails exist.
        return response()->json(['message' => 'If that email is registered, a reset code has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $customer = Customer::where('email', strtolower($data['email']))->first();

        if ($customer === null || ! $this->otp->verify($customer, VerificationPurpose::PasswordReset, $data['code'])) {
            return response()->json(['message' => 'The code is invalid or has expired.'], 422);
        }

        $customer->forceFill([
            'password_hash' => $data['password'],
            // Receiving the code proves the customer controls the inbox.
            'email_verified_at' => $customer->email_verified_at ?? now(),
        ])->save();
        $customer->tokens()->delete();

        return response()->json(['message' => 'Password reset. Please log in again.']);
    }
}
