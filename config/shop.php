<?php

return [
    // Charged once per order when it contains any physical format (hardcover/paperback). Digital-only orders ship free.
    'shipping_flat_fee' => env('SHIPPING_FLAT_FEE', '2.00'),
];
