<?php

namespace App\Exceptions;

use RuntimeException;

/** Telegram refused or didn't answer a call to the Bookly bot's API (reported to Sentry → the owner's alerts). */
class TelegramBotProblem extends RuntimeException
{
    public static function refused(string $method, string $description): self
    {
        $hint = str_contains($description, 'Unauthorized') || str_contains($description, 'Not Found')
            ? ' Check TELEGRAM_BOT_TOKEN on the server (copy it again from @BotFather → /mybots → API Token).'
            : '';

        return new self("Telegram bot {$method} failed: {$description}.{$hint}");
    }

    /** Telegram's getWebhookInfo says it couldn't deliver the bot's messages to us. */
    public static function undelivered(string $error, string $url): self
    {
        $hint = match (true) {
            str_contains($error, '404') => ' The webhook route or its secret doesn\'t match: redeploy so `php artisan telegram:webhook` runs again.',
            str_contains($error, '500') => ' The API failed on the bot\'s message: see the error just before this one.',
            str_contains($error, 'timed out') || str_contains($error, 'Connection') => ' The API was asleep or down; Telegram retries for a while.',
            default => '',
        };

        return new self("Telegram couldn't deliver the bot's messages to {$url}: {$error}.{$hint}");
    }

    public static function unreachable(string $method, string $reason): self
    {
        return new self("Telegram bot {$method}: api.telegram.org could not be reached ({$reason}).");
    }
}
