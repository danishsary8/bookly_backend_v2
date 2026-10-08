<?php

namespace App\Services\Telegram;

use App\Exceptions\TelegramBotProblem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Bookly bot on Telegram's free Bot API (core.telegram.org/bots/api). Customers open it from the website
 * with a one-time link and tap "Share my phone number"; Telegram then tells our webhook their own, proven
 * number. Replies go back in the webhook's answer, so the only calls we make are setWebhook and getMe.
 */
class TelegramBot
{
    public static function enabled(): bool
    {
        return (string) config('services.telegram_bot.token') !== '';
    }

    /** The bot's @username (without "@"), for t.me links. */
    public function username(): string
    {
        $configured = ltrim(trim((string) config('services.telegram_bot.username')), '@');
        if ($configured !== '') {
            return $configured;
        }

        return Cache::rememberForever('telegram-bot-username:'.$this->tokenId(), fn () => (string) $this->call('getMe')['username']);
    }

    /** https://t.me/<bot>?start=<code>: opens the bot and sends it "/start <code>". */
    public function link(string $code): string
    {
        return 'https://t.me/'.$this->username().'?start='.$code;
    }

    /**
     * Telegram sends this in the X-Telegram-Bot-Api-Secret-Token header of every update, so nobody else can post
     * fake phone numbers to the webhook. Made from APP_KEY and the bot token: no extra setting to manage.
     */
    public function webhookSecret(): string
    {
        return hash_hmac('sha256', 'telegram-webhook|'.config('services.telegram_bot.token'), (string) config('app.key'));
    }

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/telegram/webhook';
    }

    /** Points Telegram at our webhook (safe to repeat; the container does it at every start). */
    public function registerWebhook(): void
    {
        $this->call('setWebhook', [
            'url' => $this->webhookUrl(),
            'secret_token' => $this->webhookSecret(),
            'allowed_updates' => ['message'],
        ]);
    }

    /**
     * @return array<string, mixed> Telegram's `result`
     *
     * @throws TelegramBotProblem
     */
    public function call(string $method, array $params = []): mixed
    {
        try {
            $response = Http::timeout(10)->asJson()
                ->post(rtrim((string) config('services.telegram_bot.url'), '/').'/bot'.config('services.telegram_bot.token').'/'.$method, $params);
        } catch (Throwable $e) {
            // The request address holds the token; never let it reach logs or Sentry.
            throw TelegramBotProblem::unreachable($method, str_replace((string) config('services.telegram_bot.token'), '***', $e->getMessage()));
        }
        if ($response->json('ok') !== true) {
            throw TelegramBotProblem::refused($method, (string) ($response->json('description') ?? 'HTTP '.$response->status()));
        }

        return $response->json('result');
    }

    /** A short fingerprint of the token, so a new bot never reuses the old bot's cached username. */
    private function tokenId(): string
    {
        return substr(hash('sha256', (string) config('services.telegram_bot.token')), 0, 12);
    }
}
