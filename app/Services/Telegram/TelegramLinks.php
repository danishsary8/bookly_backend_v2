<?php

namespace App\Services\Telegram;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One-time links between a browser and the Bookly bot, kept in the cache (database on the server) for
 * 10 minutes:
 * - `code` goes into the t.me link, so Telegram (and whoever sees the link) knows it;
 * - `key` stays in the browser that asked, and is the only way to read the result (a sign-in token).
 *
 * Purposes: `login` (guest, "Continue with Telegram") and `phone` (a signed-in customer confirms their number).
 * States: pending → done (phone shared and accepted) or failed (with a message for the customer).
 */
class TelegramLinks
{
    public const MINUTES = 10;

    /** @return array{code: string, key: string, expires_at: string} */
    public function create(string $purpose, ?int $customerId = null): array
    {
        $code = Str::random(32);
        $key = Str::random(40);
        $expiresAt = now()->addMinutes(self::MINUTES);
        Cache::put($this->codeKey($code), [
            'purpose' => $purpose,
            'customer_id' => $customerId,
            'key_hash' => hash('sha256', $key),
            'status' => 'pending',
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);
        Cache::put($this->keyKey($key), $code, $expiresAt);

        return ['code' => $code, 'key' => $key, 'expires_at' => $expiresAt->toIso8601String()];
    }

    /** @return array<string, mixed>|null the link a t.me code points at */
    public function find(string $code): ?array
    {
        return $this->valid($code) ? Cache::get($this->codeKey($code)) : null;
    }

    /** @return array{0: string, 1: array<string, mixed>}|null [code, link] for the browser's private key */
    public function findByKey(string $key): ?array
    {
        $code = Cache::get($this->keyKey($key));
        $link = is_string($code) ? $this->find($code) : null;

        return $link !== null && hash_equals($link['key_hash'], hash('sha256', $key)) ? [$code, $link] : null;
    }

    /** Records the outcome (kept until the link expires, so the browser can read it). */
    public function finish(string $code, array $outcome): void
    {
        $link = $this->find($code);
        if ($link !== null) {
            Cache::put($this->codeKey($code), [...$link, ...$outcome], Carbon::parse($link['expires_at']));
        }
    }

    /** A sign-in result is handed out once. */
    public function forget(string $code, string $key): void
    {
        Cache::forget($this->codeKey($code));
        Cache::forget($this->keyKey($key));
    }

    /** The chat that opened a link remembers it until the customer shares their number. */
    public function rememberChat(int $chatId, string $code): void
    {
        Cache::put('telegram-chat:'.$chatId, $code, now()->addMinutes(self::MINUTES));
    }

    public function codeForChat(int $chatId): ?string
    {
        $code = Cache::get('telegram-chat:'.$chatId);

        return is_string($code) ? $code : null;
    }

    public function forgetChat(int $chatId): void
    {
        Cache::forget('telegram-chat:'.$chatId);
    }

    private function valid(string $code): bool
    {
        return preg_match('/^[A-Za-z0-9]{32}$/', $code) === 1;
    }

    private function codeKey(string $code): string
    {
        return 'telegram-link:'.$code;
    }

    private function keyKey(string $key): string
    {
        return 'telegram-link-key:'.hash('sha256', $key);
    }
}
