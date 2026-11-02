<?php

namespace App\Services\Payments\Khqr;

use RuntimeException;

/** Bakong could not tell us whether a QR was paid. `reason` is safe to show to staff (never contains the token). */
class BakongUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
