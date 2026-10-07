<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\VerificationPurpose;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Notifications\AccountSecurityNotice;
use App\Rules\Turnstile;
use App\Services\Auth\OtpService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Account → Sign-in & security: add an email (phone-only Facebook customers) or change it.
 *
 * Step 1 (POST /me/email) sends a code to the new address; accounts with a password type it again, so a
 * stolen session can't move the account to the thief's inbox. Step 2 (POST /me/email/verify) saves the
 * address as verified once its code comes back, signs out other devices, and tells the old (proven)
 * address what happened.
 */
class EmailController extends Controller
{
    public const EMAIL_TAKEN = 'This email is already used by another Bookly account.';

    /** Codes per account per hour, whatever address they go to: the form can't be used to spam inboxes. */
    public const CODES_PER_HOUR = 5;

    public function __construct(private readonly OtpService $otp) {}

    public function store(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => [$customer->password_hash !== null ? 'required' : 'nullable', 'string'],
            'turnstile_token' => [new Turnstile($request->ip())],
        ]);
        $email = strtolower($data['email']);

        if ($customer->password_hash !== null && ! Hash::check($data['password'], $customer->password_hash)) {
            throw ValidationException::withMessages(['password' => 'That password is incorrect.']);
        }
        if ($email === $customer->email) {
            throw ValidationException::withMessages(['email' => $customer->hasVerifiedEmail()
                ? 'This is already your email.'
                : 'This is already your email. Verify it instead.']);
        }
        if ($this->taken($email, $customer)) {
            throw ValidationException::withMessages(['email' => self::EMAIL_TAKEN]);
        }

        $key = 'email-change:'.$customer->getKey();
        if (RateLimiter::tooManyAttempts($key, self::CODES_PER_HOUR)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
            throw ValidationException::withMessages(['email' => 'You have asked for '.self::CODES_PER_HOUR." codes in the last hour. Try again in {$minutes} min."]);
        }
        RateLimiter::hit($key, 3600);

        $this->otp->issueToNewEmail($customer, $email);

        return response()->json(['message' => "We sent a 6-digit code to {$email}.", 'email' => $email]);
    }

    public function verify(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $data = $request->validate(['code' => ['required', 'digits:6']]);

        $email = $this->otp->verifyEmailChange($customer, $data['code']);
        if ($email === null) {
            return response()->json(['message' => 'The code is invalid or has expired.', 'errors' => ['code' => ['The code is invalid or has expired.']]], 422);
        }
        if ($this->taken($email, $customer)) {
            return response()->json(['message' => self::EMAIL_TAKEN], 422);
        }

        $old = $customer->hasVerifiedEmail() ? $customer->email : null;
        try {
            DB::transaction(function () use ($customer, $email) {
                $customer->forceFill(['email' => $email, 'email_verified_at' => now()])->save();
                // Codes sent to the old address must not work any more, and other devices sign in again.
                $this->otp->cancel($customer, VerificationPurpose::EmailVerify);
                $this->otp->cancel($customer, VerificationPurpose::PasswordReset);
                $customer->tokens()->where('id', '!=', $customer->currentAccessToken()->id)->delete();
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => self::EMAIL_TAKEN], 422);
        }

        if ($old !== null) {
            Notification::route('mail', $old)->notify(new AccountSecurityNotice(
                'Your Bookly email was changed',
                'The email on your Bookly account was changed to '.self::masked($email).'. Sign-in and order emails now go there.',
            ));
        }

        return response()->json([
            'message' => $old !== null ? 'Email changed. Other devices were signed out.' : 'Email added. Other devices were signed out.',
            'customer' => new CustomerResource($customer),
        ]);
    }

    /** Any other account holding the address, closed ones too (their email stays reserved until erased). */
    private function taken(string $email, Customer $customer): bool
    {
        return Customer::withTrashed()->where('email', $email)->whereKeyNot($customer->getKey())->exists();
    }

    /** "s***@example.com": enough for the owner to recognise, not a gift to whoever reads the old inbox. */
    public static function masked(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
