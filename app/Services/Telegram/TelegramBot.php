<?php

namespace App\Services\Telegram;

use App\Exceptions\TelegramBotProblem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    /** Where Telegram sends the bot's messages: APP_URL, or the address a request came in on. */
    public function webhookUrl(?string $base = null): string
    {
        return rtrim($base ?? (string) config('app.url'), '/').'/api/v1/telegram/webhook';
    }

    /** Points Telegram at our webhook (safe to repeat; the container does it at every start). */
    public function registerWebhook(?string $base = null): void
    {
        $this->call('setWebhook', [
            'url' => $this->webhookUrl($base),
            'secret_token' => $this->webhookSecret(),
            'allowed_updates' => ['message'],
        ]);
    }

    /**
     * Makes sure Telegram really delivers the bot's messages to this API. Checked when a customer starts a link,
     * at most every 10 minutes: if Telegram has another address (or none, e.g. a wrong APP_URL at start-up),
     * this API's own address is set; if Telegram reports that delivering failed lately, the owner is alerted
     * (Sentry) with Telegram's reason.
     *
     * Found live (2026-10-08): the bot never answered "Start", and nothing said why.
     *
     * @throws TelegramBotProblem
     */
    public function ensureWebhook(string $base): void
    {
        $expected = $this->webhookUrl($base);
        $checked = 'telegram-webhook-checked:'.$this->tokenId().':'.md5($expected);
        if (Cache::has($checked)) {
            return;
        }
        $info = $this->call('getWebhookInfo');
        if (($info['url'] ?? '') !== $expected) {
            Log::warning('Telegram bot webhook was '.(($info['url'] ?? '') ?: 'not set').'; now '.$expected);
            $this->registerWebhook($base);
        } elseif (filled($info['last_error_message'] ?? null) && (int) ($info['last_error_date'] ?? 0) > now()->subMinutes(30)->timestamp) {
            report(TelegramBotProblem::undelivered((string) $info['last_error_message'], $expected));
        }
        Cache::put($checked, true, now()->addMinutes(10));
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
