<?php

namespace App\Services\Auth;

use App\Enums\VerificationPurpose;
use App\Models\Customer;
use App\Models\StaffUser;
use App\Models\VerificationToken;
use App\Notifications\OtpCodeNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

class OtpService
{
    public const MAX_WRONG_GUESSES = 5;

    /** Emails a code (email verification or password reset). Phone numbers are proven in the Telegram bot instead. */
    public function issue(Customer|StaffUser $user, VerificationPurpose $purpose): void
    {
        [$code, $ttl] = $this->newCode($user, $purpose);

        $user->notify(new OtpCodeNotification($code, $purpose, $ttl));
    }

    /**
     * A sign-in code for the Bookly Telegram bot to send (POST /auth/phone/code). Returns the code; only its hash
     * is stored. Same rules as email codes: a new code cancels the old one, 5 wrong guesses throw it away.
     */
    public function issuePhoneLoginCode(Customer $customer, int $ttlMinutes): string
    {
        return $this->newCode($customer, VerificationPurpose::PhoneLogin, $ttlMinutes)[0];
    }

    /**
     * Emails a code to an address the customer wants to add or switch to. The address is only saved once its
     * code comes back (verifyEmailChange), so a typo never replaces the email the customer already has.
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

    /** The address an email-change code was sent to and not yet confirmed, if any. */
    public function pendingEmail(Customer $customer): ?string
    {
        return Cache::get($this->pendingEmailKey($customer));
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

    /** @return array{string, int} the new code and its lifetime in minutes */
    private function newCode(Customer|StaffUser $user, VerificationPurpose $purpose, ?int $ttlMinutes = null): array
    {
        $this->tokensFor($user, $purpose)->whereNull('used_at')->update(['used_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), config('auth.otp.length', 6), '0', STR_PAD_LEFT);
        $ttl = $ttlMinutes ?? (int) config('auth.otp.ttl_minutes', 15);

        VerificationToken::create([
            'user_type' => $this->typeOf($user),
            'user_id' => $user->getKey(),
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($ttl),
        ]);

        return [$code, $ttl];
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
            // Start the counter first: the database store (production) returns false when incrementing a
            // missing key, so incrementing alone never counted. add() keeps an existing count.
            Cache::add($key = "otp-wrong:{$token->id}", 0, $token->expires_at);
            $wrong = (int) Cache::increment($key);
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
