<?php

namespace App\Services\Credit;

use InvalidArgumentException;
use RuntimeException;

/** A code can be recreated for scheduled mail without storing the redeemable value. */
final class GiftCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function forId(int $id): string
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid gift card identifier.');
        }
        $bytes = hash_hmac('sha256', 'bookly-gift-code-v1|'.$id, $this->key(), true);
        $bits = '';
        foreach (str_split(substr($bytes, 0, 10)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $code = '';
        foreach (str_split($bits, 5) as $chunk) {
            $code .= self::ALPHABET[bindec($chunk)];
        }

        return $code;
    }

    public function fingerprint(string $code): string
    {
        return hash_hmac('sha256', 'bookly-gift-lookup-v1|'.$code, $this->key());
    }

    private function key(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('Application key is required for gift cards.');
        }

        return $key;
    }
}
