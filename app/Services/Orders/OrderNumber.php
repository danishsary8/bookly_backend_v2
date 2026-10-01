<?php

namespace App\Services\Orders;

use App\Models\Order;

final class OrderNumber
{
    // No 0/O, 1/I/L so numbers are easy to read out over the phone.
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** ORD-YYYYMMDD-XXXXX, unique across all orders (including soft-deleted ones). */
    public static function generate(): string
    {
        do {
            $suffix = '';
            for ($i = 0; $i < 5; $i++) {
                $suffix .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $number = 'ORD-'.now()->format('Ymd').'-'.$suffix;
        } while (Order::withTrashed()->where('order_number', $number)->exists());

        return $number;
    }
}
