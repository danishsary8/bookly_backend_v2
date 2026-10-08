<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\VerificationPurpose;
use App\Http\Controllers\Api\V1\Concerns\IssuesTokens;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Rules\Turnstile;
use App\Services\Auth\OtpService;
use App\Services\Customers\UnverifiedCustomers;
use App\Services\Telegram\TelegramBot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use IssuesTokens;

    private const EMAIL_TAKEN = 'An account with this email already exists. Sign in, or reset your password if you forgot it.';

    public const PHONE_TAKEN = 'This number is already on another Bookly account.';

    /** What the sign-up form can offer (GET /auth/options). */
    public function options(): JsonResponse
    {
        return response()->json(['telegram' => TelegramBot::enabled()]);
    }

    public function __construct(private readonly OtpService $otp, private readonly UnverifiedCustomers $unverified) {}

    public function register(Request $request): JsonResponse
    {
        $byTelegram = $request->input('verify_by') === 'telegram';
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            // Owner (2026-10-08): with Telegram the phone proves the account, so the email is optional.
            'email' => [$byTelegram ? 'nullable' : 'required', 'email', 'max:190', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && $this->emailIsTaken(strtolower($value))) {
                    $fail(self::EMAIL_TAKEN);
                }
            }],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            // A contact number for email sign-ups; with Telegram the proven number comes from the bot afterwards.
            'phone' => ['nullable', 'string', 'max:30'],
            'verify_by' => ['nullable', Rule::in(['email', 'telegram'])],
            'turnstile_token' => [new Turnstile($request->ip())],
        ]);
        if ($byTelegram && ! TelegramBot::enabled()) {
            throw ValidationException::withMessages(['verify_by' => TelegramAuthController::OFF]);
        }
        $email = filled($data['email'] ?? null) ? strtolower($data['email']) : null;
        $this->unverified->pruneIfDue();
        // An unfinished sign-up with this email is taken over below.
        $existing = $email !== null ? Customer::where('email', $email)->first() : null;

        // One transaction: if the code email can't be sent, nothing changes (no half-made account,
        // no half-replaced one), so the customer can simply try again with the same email.
        try {
            $customer = DB::transaction(function () use ($data, $email, $existing, $byTelegram) {
                $customer = $existing ?? new Customer(['email' => $email]);
                $customer->fill([
                    'name' => $data['name'],
                    'password_hash' => $data['password'],
                    'phone' => $byTelegram ? null : ($data['phone'] ?? null),
                ]);
                if ($existing !== null) {
                    $customer->created_at = now(); // the 48 hours to verify start again
                    $customer->tokens()->delete();
                }
                $customer->save();
                if (! $byTelegram) {
                    $this->otp->issue($customer, VerificationPurpose::EmailVerify);
                }

                return $customer;
            });
        } catch (UniqueConstraintViolationException) {
            // Two sign-ups with the same new email at the same moment: the other one won.
            throw ValidationException::withMessages(['email' => self::EMAIL_TAKEN]);
        }

        return response()->json([
            'message' => $byTelegram ? 'Account created. Confirm your phone number in Telegram.' : 'Account created. We sent a 6-digit code to your email.',
            'verify_by' => $byTelegram ? 'telegram' : 'email',
            'customer' => new CustomerResource($customer),
            ...$this->issueToken($customer, ['customer']),
        ], 201);
    }

    /**
     * An email that was signed up but never verified isn't anyone's yet: signing up again takes it over
     * (new name and password, old sessions and codes cancelled). Verified, deactivated or deleted
     * accounts keep their email.
     */
    private function emailIsTaken(string $email): bool
    {
        $existing = Customer::withTrashed()->where('email', $email)->first();

        return $existing !== null && ($existing->trashed() || $existing->isVerified() || ! $existing->is_active);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'turnstile_token' => [new Turnstile($request->ip())],
        ]);

        $customer = Customer::where('email', strtolower($data['email']))->first();

        if ($customer === null || $customer->password_hash === null || ! Hash::check($data['password'], $customer->password_hash)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        if (! $customer->is_active) {
            return response()->json(['message' => 'This account has been deactivated. Please contact support.'], 403);
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

        if ($customer->email === null) {
            return response()->json(['message' => 'Your account has no email address.'], 422);
        }
        if ($customer->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email is already verified.']);
        }

        if (! $this->otp->verify($customer, VerificationPurpose::EmailVerify, $data['code'])) {
            return response()->json(['message' => 'The code is invalid or has expired.'], 422);
        }

        $customer->forceFill(['email_verified_at' => now()])->save();

        return response()->json(['message' => 'Email verified.', 'customer' => new CustomerResource($customer)]);
    }

    /** A new email code. */
    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate(['turnstile_token' => [new Turnstile($request->ip())]]);
        $customer = $request->user();

        if ($customer->email === null) {
            throw ValidationException::withMessages(['email' => 'Your account has no email address.']);
        }
        if (! $customer->hasVerifiedEmail()) {
            $this->otp->issue($customer, VerificationPurpose::EmailVerify);
        }

        return response()->json(['message' => 'If your email is not verified yet, a new code has been sent.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'turnstile_token' => [new Turnstile($request->ip())]]);
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
