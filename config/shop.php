<?php

return [
    // Charged once per order when it contains any physical format (hardcover/paperback). Digital-only orders ship free.
    'shipping_flat_fee' => env('SHIPPING_FLAT_FEE', '2.00'),

    // Customers can request a return for physical formats up to this many days after delivery.
    'return_window_days' => (int) env('RETURN_WINDOW_DAYS', 14),
];
