<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\StaffUser;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Sends a notification without ever failing the action that triggered it.
 * Production runs the queue as `sync`, so a mail outage would otherwise surface inside the request
 * (after the change is already saved). Failures are reported to Sentry and swallowed.
 */
class SafeNotifier
{
    public static function toCustomer(?Customer $customer, Notification $notification): void
    {
        if ($customer === null || blank($customer->email)) {
            return; // phone-only account (Telegram sign-up): nothing to email
        }

        try {
            $customer->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function toStaff(?StaffUser $staff, Notification $notification): void
    {
        if ($staff === null || blank($staff->email)) {
            return;
        }

        try {
            $staff->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
