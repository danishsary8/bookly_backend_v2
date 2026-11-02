<?php

/*
 * Online payments (KHQR payments design 2026-11-02). Test mode is the default: the shop shows a real, scannable KHQR and
 * a "Simulate payment" button, and never calls Bakong. Set KHQR_MODE=bakong (with KHQR_ACCOUNT_ID and BAKONG_TOKEN) to
 * check real payments. BAKONG_TOKEN is a secret: Render settings only.
 */
return [
    'khqr' => [
        'enabled' => (bool) env('KHQR_ENABLED', true),
        'mode' => env('KHQR_MODE', 'test') === 'bakong' ? 'bakong' : 'test',
        'account_id' => env('KHQR_ACCOUNT_ID', 'bookly@devb'),
        'merchant_name' => env('KHQR_MERCHANT_NAME', 'Bookly'),
        'city' => env('KHQR_CITY', 'Phnom Penh'),
        'pay_minutes' => max(1, (int) env('KHQR_PAY_MINUTES', 15)),
    ],
    'bakong' => [
        'api_url' => rtrim((string) env('BAKONG_API_URL', 'https://api-bakong.nbc.gov.kh'), '/'),
        'relay_url' => rtrim((string) env('BAKONG_RELAY_URL', ''), '/'),
        'token' => env('BAKONG_TOKEN'),
        'timeout' => 5,
    ],
];
