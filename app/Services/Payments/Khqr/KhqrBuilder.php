<?php

namespace App\Services\Payments\Khqr;

use InvalidArgumentException;

/**
 * Builds an individual, dynamic KHQR string (Bakong's EMVCo-based QR), following NBC's official `bakong-khqr` SDK
 * 1.0.20 (KHQR payments design 2026-11-02, section 3.2). Tags: 00 format, 01 dynamic (12), 29 Bakong account,
 * 52 category, 53 currency, 54 amount, 58 country, 59 name, 60 city, 62 bill number / store label,
 * 99 created + expires (ms), 63 CRC-16/CCITT-FALSE.
 */
final class KhqrBuilder
{
    private const CURRENCY = ['USD' => '840', 'KHR' => '116'];

    public static function build(
        string $accountId,
        string $merchantName,
        string $city,
        string $currency,
        string $amount,
        int $createdAtMs,
        int $expiresAtMs,
        ?string $billNumber = null,
        ?string $storeLabel = null,
    ): string {
        if (! preg_match('/^[^@\s]{1,32}@[^@\s]{1,32}$/', $accountId)) {
            throw new InvalidArgumentException('The Bakong account ID looks like name@bank.');
        }
        if (! isset(self::CURRENCY[$currency])) {
            throw new InvalidArgumentException('KHQR currency must be USD or KHR.');
        }
        self::text($merchantName, 25, 'merchant name');
        self::text($city, 15, 'city');
        if ($billNumber !== null) {
            self::text($billNumber, 25, 'bill number');
        }
        if ($storeLabel !== null) {
            self::text($storeLabel, 25, 'store label');
        }
        if ($expiresAtMs <= $createdAtMs || strlen((string) $expiresAtMs) !== 13 || strlen((string) $createdAtMs) !== 13) {
            throw new InvalidArgumentException('The expiry must be a 13-digit millisecond time after the creation time.');
        }

        $additional = ($billNumber !== null ? self::tlv('01', $billNumber) : '').($storeLabel !== null ? self::tlv('03', $storeLabel) : '');

        $qr = self::tlv('00', '01')
            .self::tlv('01', '12')
            .self::tlv('29', self::tlv('00', $accountId))
            .self::tlv('52', '5999')
            .self::tlv('53', self::CURRENCY[$currency])
            .self::tlv('54', self::amount($currency, $amount))
            .self::tlv('58', 'KH')
            .self::tlv('59', $merchantName)
            .self::tlv('60', $city)
            .($additional !== '' ? self::tlv('62', $additional) : '')
            .self::tlv('99', self::tlv('00', (string) $createdAtMs).self::tlv('01', (string) $expiresAtMs))
            .'6304';

        return $qr.self::crc16($qr);
    }

    /** Bakong finds a payment by the lower-case hex MD5 of the whole QR string. */
    public static function md5(string $qr): string
    {
        return md5($qr);
    }

    /** CRC-16/CCITT-FALSE (poly 0x1021, start 0xFFFF), 4 upper-case hex digits. */
    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return sprintf('%04X', $crc);
    }

    public static function hasValidCrc(string $qr): bool
    {
        return strlen($qr) > 8 && substr($qr, -8, 4) === '6304' && self::crc16(substr($qr, 0, -4)) === strtoupper(substr($qr, -4));
    }

    /** USD: whole dollars as "5", otherwise 2 decimals ("12.50"); KHR: whole riel. Same as the SDK. */
    private static function amount(string $currency, string $amount): string
    {
        if ($currency === 'KHR') {
            if (! preg_match('/^\d+$/', $amount) || (int) $amount <= 0) {
                throw new InvalidArgumentException('A KHR amount is a whole number of riel above 0.');
            }

            return (string) (int) $amount;
        }
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
            throw new InvalidArgumentException('A USD amount is above 0 with at most 2 decimals.');
        }
        $cents = (int) round((float) $amount * 100);

        return $cents % 100 === 0 ? (string) intdiv($cents, 100) : sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private static function text(string $value, int $max, string $what): void
    {
        if ($value === '' || strlen($value) > $max || ! preg_match('/^[\x20-\x7E]+$/', $value)) {
            throw new InvalidArgumentException("The {$what} must be 1 to {$max} plain (ASCII) characters.");
        }
    }

    private static function tlv(string $tag, string $value): string
    {
        return $tag.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }
}
