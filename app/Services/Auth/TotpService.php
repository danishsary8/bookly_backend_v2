<?php

namespace App\Services\Auth;

use Illuminate\Support\Carbon;

/** RFC 6238 time-based one-time passwords (Google Authenticator, Authy, 1Password). */
class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD = 30;

    private const DIGITS = 6;

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function provisioningUri(string $secret, string $accountName, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=%d&period=%d',
            rawurlencode($issuer), rawurlencode($accountName), $secret, rawurlencode($issuer), self::DIGITS, self::PERIOD,
        );
    }

    public function codeAt(string $secret, int $timestamp): string
    {
        $counter = pack('N*', 0, intdiv($timestamp, self::PERIOD));
        $hash = hash_hmac('sha1', $counter, $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Accepts the current code and one period either side to tolerate clock drift. */
    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $timestamp ??= Carbon::now()->getTimestamp();
        $code = trim($code);

        foreach ([-1, 0, 1] as $step) {
            if (hash_equals($this->codeAt($secret, $timestamp + $step * self::PERIOD), $code)) {
                return true;
            }
        }

        return false;
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $output .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $output;
    }

    private function base32Decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($secret, '='))) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr(bindec($byte));
            }
        }

        return $output;
    }
}
