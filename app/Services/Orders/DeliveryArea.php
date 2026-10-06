<?php

namespace App\Services\Orders;

use App\Models\CustomerAddress;

/**
 * Which delivery area an address is in, and what delivery costs there.
 * Phnom Penh is matched on the address's city or province, ignoring case, spaces and dashes
 * ("Phnom Penh", "phnom-penh", "PhnomPenh", "ភ្នំពេញ"); everywhere else counts as the provinces.
 */
class DeliveryArea
{
    public const PHNOM_PENH = 'phnom_penh';

    public const PROVINCES = 'provinces';

    public static function for(?CustomerAddress $address): string
    {
        if ($address === null) {
            return self::PROVINCES;
        }

        foreach ([$address->city, $address->state] as $part) {
            $flat = mb_strtolower(preg_replace('/[\s\-_.]+/u', '', (string) $part));
            if (str_contains($flat, 'phnompenh') || str_contains($flat, 'ភ្នំពេញ')) {
                return self::PHNOM_PENH;
            }
        }

        return self::PROVINCES;
    }

    /** Delivery fee for an area, as a decimal string in USD. */
    public static function fee(string $area): string
    {
        return (string) config("shop.shipping_fees.{$area}");
    }
}
