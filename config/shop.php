<?php

return [
    // The website's address, for links in staff alerts (e.g. https://bookly-frontend-five.vercel.app). Optional.
    'url' => env('SHOP_URL'),

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

    // Hosts without a scheduler (Render free) set this to true: the housekeeping jobs then run, when due, after any API
    // request (the uptime monitor's 5-minute ping is enough). Leave it off where `schedule:work` runs.
    'maintenance_on_request' => (bool) env('RUN_MAINTENANCE_ON_REQUEST', false),

    // Maximum marketing emails sent per shop-time calendar day (Resend free allows 100/day total).
    'marketing_daily_limit' => (int) env('MARKETING_DAILY_LIMIT', 60),
];
