<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Cambodian phone numbers (+855 only, owner's choice): "012 345 678", "+855 12 345 678" and
 * "85512345678" all become "+85512345678".
 */
final class PhoneNumber
{
    public const REGION = 'KH';

    /** The number in international form, or null if it isn't a valid Cambodian number. */
    public static function normalize(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '' || strlen($input) > 30) {
            return null;
        }
        $util = PhoneNumberUtil::getInstance();
        try {
            $number = $util->parse($input, self::REGION);
        } catch (NumberParseException) {
            return null;
        }
        if (! $util->isValidNumber($number) || $util->getRegionCodeForNumber($number) !== self::REGION) {
            return null;
        }

        return $util->format($number, PhoneNumberFormat::E164);
    }

    /** "+855 12 345 678", for showing to people. */
    public static function display(string $e164): string
    {
        $util = PhoneNumberUtil::getInstance();
        try {
            return $util->format($util->parse($e164, self::REGION), PhoneNumberFormat::INTERNATIONAL);
        } catch (NumberParseException) {
            return $e164;
        }
    }
}
