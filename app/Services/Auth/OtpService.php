<?php

namespace App\Services\Auth;

use App\Enums\VerificationPurpose;
use App\Exceptions\TelegramCodeNotSent;
use App\Models\Customer;
use App\Models\StaffUser;
use App\Models\VerificationToken;
use App\Notifications\OtpCodeNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public const MAX_WRONG_GUESSES = 5;

    /** Telegram codes per phone number (owner's limits): they cost money, so bots can't farm them. */
    public const PHONE_CODES_PER_HOUR = 3;

    public const PHONE_CODES_PER_DAY = 10;

    public function __construct(private readonly TelegramGateway $telegram) {}

    /** Emails a code (email verification or password reset). */
    public function issue(Customer|StaffUser $user, VerificationPurpose $purpose): void
    {
        [$code, $ttl] = $this->newCode($user, $purpose);

        $user->notify(new OtpCodeNotification($code, $purpose, $ttl));
    }

    /**
     * Sends a code to a phone number's Telegram. The number is only saved on the account once the code
     * comes back (verifyPhone), so a typo never replaces a number the customer already proved.
     *
     * @throws TelegramCodeNotSent
     * @throws ValidationException when this number has had too many codes
     */
    public function issueToPhone(Customer $customer, string $phoneE164): void
    {
        $this->guardPhoneLimits($phoneE164);
        [$code, $ttl] = $this->newCode($customer, VerificationPurpose::PhoneVerify);

        try {
            $this->telegram->send($phoneE164, $code, $ttl * 60);
        } catch (TelegramCodeNotSent $e) {
            $this->tokensFor($customer, VerificationPurpose::PhoneVerify)->whereNull('used_at')->update(['used_at' => now()]);
            throw $e;
        }
        RateLimiter::hit('phone-codes-hour:'.$phoneE164, 3600);
        RateLimiter::hit('phone-codes-day:'.$phoneE164, 86400);
        Cache::put($this->pendingKey($customer), $phoneE164, now()->addMinutes($ttl));
    }

    /**
     * A sign-in code to an account's verified number (POST /auth/phone-login). Same per-number limits as
     * every Telegram code.
     *
     * @throws TelegramCodeNotSent
     */
    public function issueLoginCode(Customer $customer): void
    {
        [$code, $ttl] = $this->newCode($customer, VerificationPurpose::PhoneLogin);
        try {
            $this->telegram->send($customer->phone_e164, $code, $ttl * 60);
        } catch (TelegramCodeNotSent $e) {
            $this->cancel($customer, VerificationPurpose::PhoneLogin);
            throw $e;
        }
    }

    /**
     * Counts a code request against a number, whether or not an account has it, so the limits answer the
     * same for every number and can't be used to find out which numbers are customers.
     *
     * @throws ValidationException when this number has had too many codes
     */
    public function spendPhoneAllowance(string $phoneE164): void
    {
        $this->guardPhoneLimits($phoneE164);
        RateLimiter::hit('phone-codes-hour:'.$phoneE164, 3600);
        RateLimiter::hit('phone-codes-day:'.$phoneE164, 86400);
    }

    /** The number waiting for its code, if any. */
    public function pendingPhone(Customer $customer): ?string
    {
        return Cache::get($this->pendingKey($customer));
    }

    /** Checks a Telegram code; returns the number it was sent to, or null if the code is wrong. */
    public function verifyPhone(Customer $customer, string $code): ?string
    {
        $phone = $this->pendingPhone($customer);
        if ($phone === null || ! $this->verify($customer, VerificationPurpose::PhoneVerify, $code)) {
            return null;
        }
        Cache::forget($this->pendingKey($customer));

        return $phone;
    }

    /**
     * Emails a code to an address the customer wants to add or switch to. Like a new phone number, the
     * address is only saved once its code comes back (verifyEmailChange), so a typo never replaces the
     * email the customer already has.
     */
    public function issueToNewEmail(Customer $customer, string $email): void
    {
        [$code, $ttl] = $this->newCode($customer, VerificationPurpose::EmailChange);
        Cache::put($this->pendingEmailKey($customer), $email, now()->addMinutes($ttl));

        Notification::route('mail', $email)->notify(new OtpCodeNotification($code, VerificationPurpose::EmailChange, $ttl));
    }

    /** Checks an email-change code; returns the address it was sent to, or null if the code is wrong. */
    public function verifyEmailChange(Customer $customer, string $code): ?string
    {
        $email = Cache::get($this->pendingEmailKey($customer));
        if ($email === null || ! $this->verify($customer, VerificationPurpose::EmailChange, $code)) {
            return null;
        }
        Cache::forget($this->pendingEmailKey($customer));

        return $email;
    }

    /** Cancels a customer's unused codes of one kind (e.g. a reset code for an address they just left). */
    public function cancel(Customer $customer, VerificationPurpose $purpose): void
    {
        $this->tokensFor($customer, $purpose)->whereNull('used_at')->update(['used_at' => now()]);
    }

    private function pendingEmailKey(Customer $customer): string
    {
        return 'email-pending:'.$customer->getKey();
    }

    private function guardPhoneLimits(string $phoneE164): void
    {
        foreach ([['phone-codes-hour:', self::PHONE_CODES_PER_HOUR, 'the last hour'], ['phone-codes-day:', self::PHONE_CODES_PER_DAY, 'the last day']] as [$key, $max, $period]) {
            if (RateLimiter::tooManyAttempts($key.$phoneE164, $max)) {
                $minutes = max(1, (int) ceil(RateLimiter::availableIn($key.$phoneE164) / 60));
                throw ValidationException::withMessages([
                    'phone' => "This number has had {$max} codes in {$period}. Try again in {$minutes} min, or use email.",
                ]);
            }
        }
    }

    /** @return array{string, int} the new code and its lifetime in minutes */
    private function newCode(Customer|StaffUser $user, VerificationPurpose $purpose): array
    {
        $this->tokensFor($user, $purpose)->whereNull('used_at')->update(['used_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), config('auth.otp.length', 6), '0', STR_PAD_LEFT);
        $ttl = (int) config('auth.otp.ttl_minutes', 15);

        VerificationToken::create([
            'user_type' => $this->typeOf($user),
            'user_id' => $user->getKey(),
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($ttl),
        ]);

        return [$code, $ttl];
    }

    private function pendingKey(Customer $customer): string
    {
        return 'phone-pending:'.$customer->getKey();
    }

    /**
     * Consumes the code on success so it cannot be reused. After MAX_WRONG_GUESSES wrong codes the code is
     * thrown away, whatever IP the guesses came from, so a 6-digit code cannot be guessed from many machines.
     */
    public function verify(Customer|StaffUser $user, VerificationPurpose $purpose, string $code): bool
    {
        $token = $this->tokensFor($user, $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($token === null) {
            return false;
        }

        if (! Hash::check($code, $token->code_hash)) {
            $wrong = Cache::increment($key = "otp-wrong:{$token->id}");
            if ($wrong === 1) {
                Cache::put($key, 1, $token->expires_at);
            }
            if ($wrong >= self::MAX_WRONG_GUESSES) {
                $token->update(['used_at' => now()]);
            }

            return false;
        }

        $token->update(['used_at' => now()]);

        return true;
    }

    private function tokensFor(Customer|StaffUser $user, VerificationPurpose $purpose)
    {
        return VerificationToken::query()
            ->where('user_type', $this->typeOf($user))
            ->where('user_id', $user->getKey())
            ->where('purpose', $purpose);
    }

    private function typeOf(Customer|StaffUser $user): string
    {
        return $user instanceof StaffUser ? 'staff' : 'customer';
    }
}
