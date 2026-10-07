<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\VerificationPurpose;
use App\Exceptions\TelegramCodeNotSent;
use App\Http\Controllers\Api\V1\Concerns\IssuesTokens;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Rules\CambodianPhone;
use App\Rules\Turnstile;
use App\Services\Auth\OtpService;
use App\Services\Auth\SocialTokenVerifier;
use App\Services\Auth\TelegramGateway;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * The frontend signs the user in with Google/Facebook and sends us the provider access token;
 * we check the token was issued to our app and fetch the profile from the provider ourselves,
 * so the client cannot fake an identity.
 *
 * Owner's rules:
 * - Google / Facebook never sign in to an email that already has a real account.
 * - A new Facebook account needs a Cambodian phone number; the email is optional (many Facebook accounts
 *   have none). The first call answers 422 with `needs: "phone"`; the app asks for the number and calls
 *   again with the same token plus `phone` (and `email` if the customer wants one). The number is proven
 *   with a Telegram code when we can send one.
 */
class SocialAuthController extends Controller
{
    use IssuesTokens;

    private const COLUMNS = ['google' => 'google_id', 'facebook' => 'facebook_id'];

    public const NEEDS_PHONE = 'Add your phone number to finish signing up with Facebook.';

    public function __construct(private readonly OtpService $otp) {}

    public function __invoke(Request $request, string $provider, SocialTokenVerifier $verifier): JsonResponse
    {
        abort_unless(isset(self::COLUMNS[$provider]), 404);
        $data = $request->validate([
            'access_token' => ['required', 'string', 'max:4096'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
        ]);

        if (! $verifier->issuedToUs($provider, $data['access_token'])) {
            return $this->notVerified($provider);
        }

        try {
            $profile = Socialite::driver($provider)->stateless()->userFromToken($data['access_token']);
        } catch (Throwable) {
            return $this->notVerified($provider);
        }

        $email = $profile->getEmail() ? strtolower($profile->getEmail()) : null;
        if ($email === null && $provider === 'google') {
            return response()->json(['message' => 'Your Google account has no email address. Please register with email instead.'], 422);
        }

        // Google tells us whether it checked the address; we only trust (and link by) checked addresses.
        if ($provider === 'google' && ! filter_var($profile->getRaw()['email_verified'] ?? false, FILTER_VALIDATE_BOOL)) {
            return response()->json(['message' => 'Please verify your email address with Google first, or register with email instead.'], 422);
        }

        $column = self::COLUMNS[$provider];
        $customer = Customer::where($column, $profile->getId())->first();
        $sameEmail = $customer === null && $email !== null ? Customer::withTrashed()->where('email', $email)->first() : null;

        $account = $customer ?? ($sameEmail?->trashed() ? null : $sameEmail);
        if ($account !== null && ! $account->is_active) {
            return response()->json(['message' => 'This account has been deactivated. Please contact support.'], 403);
        }

        // Returning customer: sign in.
        if ($customer !== null) {
            if ($customer->email_verified_at === null && $customer->email !== null && $customer->email === $email) {
                $customer->forceFill(['email_verified_at' => now()])->save();
            }

            return $this->signedIn($customer, 200);
        }

        if ($sameEmail !== null && ($sameEmail->trashed() || $sameEmail->isVerified())) {
            return response()->json(['message' => $this->signInAsBefore($sameEmail)], 409);
        }

        if ($provider === 'facebook') {
            return $this->facebookSignUp($request, $data, $profile, $email, $sameEmail);
        }

        // Google: an unfinished sign-up with this email isn't anyone's account yet; Google has now proven who
        // owns the address, so it becomes theirs and the unproven password and sessions stop working.
        $created = $sameEmail === null;
        $customer = $sameEmail ?? new Customer(['email' => $email]);
        $this->takeOver($customer, $profile, $email);
        $customer->forceFill([$column => $profile->getId(), 'email_verified_at' => $customer->email_verified_at ?? now()])->save();

        return $this->signedIn($customer, $created ? 201 : 200);
    }

    /** @param  array{phone?: ?string, email?: ?string}  $data */
    private function facebookSignUp(Request $request, array $data, SocialUser $profile, ?string $facebookEmail, ?Customer $unfinished): JsonResponse
    {
        if (blank($data['phone'] ?? null)) {
            return response()->json([
                'message' => self::NEEDS_PHONE,
                'needs' => 'phone',
                'errors' => ['phone' => [self::NEEDS_PHONE]],
                'profile' => ['name' => $profile->getName(), 'email' => $facebookEmail],
                'telegram_codes' => TelegramGateway::enabled(),
            ], 422);
        }

        // The phone step triggers a paid Telegram code, so it gets the bot check.
        $request->validate([
            'phone' => [new CambodianPhone],
            'turnstile_token' => [new Turnstile($request->ip())],
        ]);
        $phone = PhoneNumber::normalize($data['phone']);
        $email = filled($data['email'] ?? null) ? strtolower($data['email']) : null;
        $emailProven = $email !== null && $email === $facebookEmail;

        if (Customer::withTrashed()->where('phone_e164', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => AuthController::PHONE_TAKEN]);
        }
        if ($email !== null && Customer::withTrashed()->where('email', $email)->when($unfinished, fn ($q) => $q->whereKeyNot($unfinished->getKey()))->exists()) {
            throw ValidationException::withMessages(['email' => 'This email is already used by another Bookly account. Leave it empty or use another one.']);
        }

        try {
            [$customer, $verifyBy] = DB::transaction(function () use ($profile, $facebookEmail, $unfinished, $phone, $email, $emailProven) {
                $customer = $unfinished ?? new Customer;
                $this->takeOver($customer, $profile, $email ?? $facebookEmail);
                $customer->forceFill([
                    'facebook_id' => $profile->getId(),
                    'email' => $email,
                    'email_verified_at' => $emailProven ? now() : null,
                    'phone' => PhoneNumber::display($phone),
                ])->save();

                return [$customer, $this->sendFirstCode($customer, $phone)];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => AuthController::PHONE_TAKEN]);
        }

        return $this->signedIn($customer, $unfinished === null ? 201 : 200, ['verify_by' => $verifyBy]);
    }

    /**
     * Proves the new Facebook account: a Telegram code to the phone when we can send one; otherwise an email
     * code if the customer typed an email Facebook didn't confirm. Returns where the code went, or null when
     * nothing is needed (Facebook confirmed the email; the phone can be verified later).
     */
    private function sendFirstCode(Customer $customer, string $phone): ?string
    {
        $problem = null;
        if (TelegramGateway::enabled()) {
            try {
                $this->otp->issueToPhone($customer, $phone);

                return 'telegram';
            } catch (TelegramCodeNotSent $e) {
                $problem = $e->getMessage();
            }
        }
        if ($customer->isVerified()) {
            return null;
        }
        if ($customer->email !== null) {
            $this->otp->issue($customer, VerificationPurpose::EmailVerify);

            return 'email';
        }

        throw ValidationException::withMessages(['phone' => $problem !== null
            ? "We couldn't send a Telegram code to this number. Check it has Telegram, or add your email to get the code there."
            : "We can't send codes to phones yet. Add your email to get your code there."]);
    }

    /** Unfinished sign-ups lose their unproven password and sessions when a provider claims them. */
    private function takeOver(Customer $customer, SocialUser $profile, ?string $email): void
    {
        if ($customer->exists) {
            $customer->tokens()->delete();
            $customer->forceFill(['password_hash' => null]);
        }
        $customer->name = $profile->getName() ?: ($customer->name ?: ($email ?? 'Bookly reader'));
    }

    private function signedIn(Customer $customer, int $status, array $extra = []): JsonResponse
    {
        return response()->json([
            ...$extra,
            'customer' => new CustomerResource($customer),
            ...$this->issueToken($customer, ['customer']),
        ], $status);
    }

    private function notVerified(string $provider): JsonResponse
    {
        return response()->json(['message' => 'Could not verify your '.ucfirst($provider).' account.'], 401);
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
