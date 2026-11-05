<?php

namespace App\Services\Ebooks;

use App\Models\Customer;
use App\Models\EbookDevice;
use App\Services\Staff\DeviceDetector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Ebook devices (owner 2026-11-06): at most MAX_DEVICES browsers per customer; the customer can remove one every
 * REMOVE_EVERY_DAYS days (so an account can't be shared by swapping devices); staff can reset all of them.
 * The browser sends a random id it keeps (X-Reader-Device); only its hash is stored.
 */
class EbookDevices
{
    public const MAX_DEVICES = 3;

    public const REMOVE_EVERY_DAYS = 30;

    /** The device for this request, registered on first use. 409 when the customer already has the maximum. */
    public function resolve(Customer $customer, ?string $deviceId, ?string $userAgent): EbookDevice
    {
        if ($deviceId === null || ! preg_match('/^[A-Za-z0-9_-]{16,100}$/', $deviceId)) {
            throw new HttpException(400, 'Missing or invalid reader device id.');
        }
        $hash = hash('sha256', $deviceId);

        return DB::transaction(function () use ($customer, $hash, $userAgent) {
            // Lock the customer row so two first reads at once can't both pass the limit.
            Customer::whereKey($customer->id)->lockForUpdate()->first();
            $device = EbookDevice::active()->where('customer_id', $customer->id)->where('device_hash', $hash)->first();
            if ($device === null) {
                if (EbookDevice::active()->where('customer_id', $customer->id)->count() >= self::MAX_DEVICES) {
                    throw new HttpException(409, __('messages.ebook_device_limit', ['max' => self::MAX_DEVICES]));
                }
                $device = EbookDevice::create([
                    'customer_id' => $customer->id,
                    'device_hash' => $hash,
                    'name' => mb_substr(DeviceDetector::detect($userAgent), 0, 100),
                ]);
            }
            if ($device->last_used_at === null || $device->last_used_at->lt(now()->subMinute())) {
                $device->forceFill(['last_used_at' => now()])->save();
            }

            return $device;
        });
    }

    /** When the customer may remove a device again (null = now). */
    public function nextRemovalAt(Customer $customer): ?Carbon
    {
        $last = EbookDevice::where('customer_id', $customer->id)->whereNotNull('removed_at')
            ->where('removed_at', '>', now()->subDays(self::REMOVE_EVERY_DAYS))->max('removed_at');

        return $last ? Carbon::parse($last)->addDays(self::REMOVE_EVERY_DAYS) : null;
    }

    public function remove(Customer $customer, int $deviceId): void
    {
        $device = EbookDevice::active()->where('customer_id', $customer->id)->findOrFail($deviceId);
        if ($next = $this->nextRemovalAt($customer)) {
            throw new HttpException(429, __('messages.ebook_device_remove_wait', ['date' => $next->timezone(config('shop.timezone', 'Asia/Phnom_Penh'))->format('j M Y')]));
        }
        $device->forceFill(['removed_at' => now()])->save();
    }

    /** Staff: frees every device and the 30-day wait. Returns how many rows were cleared. */
    public function reset(Customer $customer): int
    {
        // Deleted rather than marked removed, so the reset doesn't use up the customer's own 30-day removal.
        return EbookDevice::where('customer_id', $customer->id)->delete();
    }
}
