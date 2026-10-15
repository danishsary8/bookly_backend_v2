<?php

namespace App\Services\Auth;

use App\Enums\VerificationPurpose;
use App\Models\Customer;

/**
 * Who an email address belongs to. An address is an account's only once someone proved they read it (a code
 * sent to it, or Google/Facebook vouching for it), or while that account is closed or deactivated (kept until
 * erased). An unproven address on an account that signs in another way (a Telegram phone) is nobody's: the
 * real owner can still sign up with it, and it is then taken off that account (security review 2026-10-15:
 * otherwise anyone could put someone else's email on a Telegram account and keep a way into it later).
 */
class EmailClaims
{
    public function __construct(private readonly OtpService $otp) {}

    public function holder(string $email, ?Customer $except = null): ?Customer
    {
        return Customer::withTrashed()->where('email', $email)
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->first();
    }

    /** The holder keeps the address: proven, or the account is closed or deactivated. */
    public function keeps(Customer $holder): bool
    {
        return $holder->trashed() || ! $holder->is_active || $holder->hasVerifiedEmail();
    }

    /** Holds the address without proof while signing in another way (phone). */
    public function isUnprovenOnAnotherAccount(Customer $holder): bool
    {
        return ! $this->keeps($holder) && $holder->isVerified();
    }

    /** Takes an unproven address off its account; codes sent to it stop working. */
    public function release(Customer $holder): void
    {
        $holder->forceFill(['email' => null])->save();
        $this->otp->cancel($holder, VerificationPurpose::EmailVerify);
        $this->otp->cancel($holder, VerificationPurpose::PasswordReset);
    }
}
