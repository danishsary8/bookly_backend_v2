<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * The rule for every new password (customer sign-up, reset and change; staff reset; the create-admin command):
 * at least 8 characters with letters and numbers, and not in a known data leak (owner's choice 2026-11-04).
 *
 * The leak check asks Have I Been Pwned with only the first 5 characters of the password's SHA-1 hash (k-anonymity:
 * the password itself never leaves the server). If the service can't be reached the password is allowed, so an
 * outage there never blocks a sign-up (AppServiceProvider caps the wait at 5 seconds).
 */
final class PasswordRules
{
    public static function new(): Password
    {
        return Password::min(8)->letters()->numbers()->uncompromised();
    }
}
