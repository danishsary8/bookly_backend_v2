<?php

return [
    // Charged once per order when it contains any physical format (hardcover/paperback), by the delivery address's
    // area (App\Services\Orders\DeliveryArea). Digital-only orders ship free.
    'shipping_fees' => [
        'phnom_penh' => env('SHIPPING_FEE_PHNOM_PENH', '1.50'),
        'provinces' => env('SHIPPING_FEE_PROVINCES', '3.00'),
    ],

    // Customers can request a return for physical formats up to this many days after delivery.
    'return_window_days' => (int) env('RETURN_WINDOW_DAYS', 3),

    // Day boundaries for the admin dashboard ("today", daily sales). Timestamps are stored in UTC.
    'timezone' => env('SHOP_TIMEZONE', 'Asia/Phnom_Penh'),
];
