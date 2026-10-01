<?php

namespace App\Services\Auth;

use App\Enums\VerificationPurpose;
use App\Models\Customer;
use App\Models\StaffUser;
use App\Models\VerificationToken;
use App\Notifications\OtpCodeNotification;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    public function issue(Customer|StaffUser $user, VerificationPurpose $purpose): void
    {
        $this->tokensFor($user, $purpose)->whereNull('used_at')->update(['used_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), config('auth.otp.length', 6), '0', STR_PAD_LEFT);
        $ttl = config('auth.otp.ttl_minutes', 15);

        VerificationToken::create([
            'user_type' => $this->typeOf($user),
            'user_id' => $user->getKey(),
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($ttl),
        ]);

        $user->notify(new OtpCodeNotification($code, $purpose, $ttl));
    }

    /** Consumes the code on success so it cannot be reused. */
    public function verify(Customer|StaffUser $user, VerificationPurpose $purpose, string $code): bool
    {
        $token = $this->tokensFor($user, $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($token === null || ! Hash::check($code, $token->code_hash)) {
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
