<?php

namespace App\Services\Staff;

use App\Enums\StaffRole;
use App\Enums\VerificationPurpose;
use App\Models\StaffUser;
use App\Models\VerificationToken;
use App\Notifications\StaffInvitationNotification;
use App\Services\Admin\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Admin-only changes to staff accounts, with the rules that stop admins locking themselves out. */
class StaffManagementService
{
    public const INVITE_TTL_HOURS = 72;

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(StaffUser $admin, array $data): StaffUser
    {
        return DB::transaction(function () use ($admin, $data) {
            $member = StaffUser::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                // Nobody knows this password; the new member sets their own with the emailed code.
                'password_hash' => Str::random(64),
                'role' => $data['role'],
            ]);
            $this->audit->created($admin, $member);
            $this->sendInvitation($member);

            return $member;
        });
    }

    /**
     * A 6-digit code that works with the normal staff reset-password endpoint, but valid for
     * 72 hours instead of 15 minutes so new staff have time to act on it.
     */
    public function sendInvitation(StaffUser $member): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        VerificationToken::where('user_type', 'staff')->where('user_id', $member->id)
            ->where('purpose', VerificationPurpose::PasswordReset)->whereNull('used_at')
            ->update(['used_at' => now()]);

        VerificationToken::create([
            'user_type' => 'staff',
            'user_id' => $member->id,
            'purpose' => VerificationPurpose::PasswordReset,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addHours(self::INVITE_TTL_HOURS),
        ]);

        $member->notify(new StaffInvitationNotification($code, self::INVITE_TTL_HOURS));
    }

    public function update(StaffUser $admin, StaffUser $member, array $data): StaffUser
    {
        return DB::transaction(function () use ($admin, $member, $data) {
            $locked = $this->lockAdmins($member);
            $before = $locked->getAttributes();

            if (isset($data['role']) && $data['role'] !== $locked->role->value) {
                $this->guardNotSelf($admin, $locked, 'change your own role');
                if ($locked->role === StaffRole::Admin) {
                    $this->guardNotLastAdmin($locked, 'demoted');
                }
            }

            $locked->update($data);

            if ($locked->wasChanged('role')) {
                // Token abilities are fixed when a token is created, so old tokens would keep the old role.
                $locked->tokens()->delete();
            }
            $this->audit->updated($admin, $locked, $before);

            return $locked;
        });
    }

    public function setActive(StaffUser $admin, StaffUser $member, bool $active): StaffUser
    {
        return DB::transaction(function () use ($admin, $member, $active) {
            $locked = $this->lockAdmins($member);

            if ($locked->is_active === $active) {
                return $locked;
            }
            if (! $active) {
                $this->guardNotSelf($admin, $locked, 'deactivate your own account');
                if ($locked->role === StaffRole::Admin) {
                    $this->guardNotLastAdmin($locked, 'deactivated');
                }
                $locked->tokens()->delete();
            }

            $locked->update(['is_active' => $active]);
            $this->audit->custom($admin, $active ? 'activated' : 'deactivated', $locked, ['is_active' => ! $active], ['is_active' => $active]);

            return $locked;
        });
    }

    /** Lost phone: 2FA is switched off and must be set up again at the next login. */
    public function resetTwoFactor(StaffUser $admin, StaffUser $member): StaffUser
    {
        $this->guardNotSelf($admin, $member, 'reset your own two-factor authentication');

        return DB::transaction(function () use ($admin, $member) {
            $member->forceFill(['two_factor_secret' => null, 'two_factor_enabled' => false])->save();
            $member->tokens()->delete();
            $this->audit->custom($admin, 'two_factor_reset', $member, ['two_factor_enabled' => true], ['two_factor_enabled' => false]);

            return $member;
        });
    }

    /** Locks every active admin row (and the member) so two admins cannot demote each other at the same time. */
    private function lockAdmins(StaffUser $member): StaffUser
    {
        StaffUser::where('role', StaffRole::Admin)->where('is_active', true)->orderBy('id')->lockForUpdate()->get();

        return StaffUser::whereKey($member->id)->lockForUpdate()->firstOrFail();
    }

    private function guardNotSelf(StaffUser $admin, StaffUser $member, string $what): void
    {
        if ($admin->is($member)) {
            throw ValidationException::withMessages(['staff' => "You cannot {$what}."]);
        }
    }

    private function guardNotLastAdmin(StaffUser $member, string $what): void
    {
        $others = StaffUser::where('role', StaffRole::Admin)->where('is_active', true)->whereKeyNot($member->id)->count();

        if ($others === 0) {
            throw ValidationException::withMessages(['staff' => "The last active admin cannot be {$what}."]);
        }
    }
}
