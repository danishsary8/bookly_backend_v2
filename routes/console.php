<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Remove expired API tokens (they stop working at expiry; this just keeps the table small).
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Delete sign-ups that were never verified (also runs from sign-up and the staff customer list,
// because the free host has no scheduler).
Schedule::command('customers:prune-unverified')->hourly();
