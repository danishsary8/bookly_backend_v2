<?php

namespace App\Services\Auth;

use App\Models\Customer;
use App\Notifications\AccountSecurityNotice;
use App\Services\Staff\DeviceDetector;
use App\Support\SafeNotifier;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * New-device sign-in email (owner's choice 2026-11-04). Every customer sign-in records the device ("Chrome on
 * Windows", read from the browser). When an account that already has devices signs in on one it has never used, the
 * owner gets an email with the device, time and IP address. The first device of an account (sign-up, or the first
 * sign-in after this was added) is only recorded. A device is the browser and system, so another computer with the
 * same browser and system counts as known: this catches most, not all, strangers.
 */
class KnownDevices
{
    public const MAX_PER_CUSTOMER = 30;

    public function signedIn(Customer $customer, ?string $userAgent, ?string $ip, bool $email = true): void
    {
        if ($customer->email === DemoSeeder::EMAIL) {
            return; // shared account: every visitor is a "new device"
        }
        $device = mb_substr(DeviceDetector::detect($userAgent), 0, 100);
        $now = now();

        $known = DB::table('customer_known_devices')->where('customer_id', $customer->id);
        if ((clone $known)->where('device', $device)->update(['last_seen_at' => $now])) {
            return;
        }
        $hadDevices = (clone $known)->exists();
        try {
            DB::table('customer_known_devices')->insert(['customer_id' => $customer->id, 'device' => $device, 'first_seen_at' => $now, 'last_seen_at' => $now]);
        } catch (UniqueConstraintViolationException) {
            return; // two sign-ins on the same new device at the same moment: the other one emails
        }
        $this->keepNewest($customer);

        if ($hadDevices && $email) {
            $at = $now->copy()->timezone('Asia/Phnom_Penh')->format('j M Y, H:i');
            SafeNotifier::toCustomer($customer, new AccountSecurityNotice(
                'New sign-in to your Bookly account',
                "Your Bookly account was signed in on a new device: {$device}, on {$at} (Phnom Penh time), from {$this->ip($ip)}. You can see and sign out every device in Account → Sign-in & security.",
                'new_device',
                ['device' => $device, 'at' => $at, 'ip' => $this->ip($ip)],
            ));
        }
    }

    private function ip(?string $ip): string
    {
        return $ip ?: 'an unknown address';
    }

    /** Old, unused devices drop off, so the table stays small. */
    private function keepNewest(Customer $customer): void
    {
        $keep = DB::table('customer_known_devices')->where('customer_id', $customer->id)
            ->orderByDesc('last_seen_at')->orderByDesc('id')->limit(self::MAX_PER_CUSTOMER)->pluck('id');
        DB::table('customer_known_devices')->where('customer_id', $customer->id)->whereNotIn('id', $keep)->delete();
    }
}
