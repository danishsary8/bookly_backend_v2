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

    public static function unreachable(string $method, string $reason): self
    {
        return new self("Telegram bot {$method}: api.telegram.org could not be reached ({$reason}).");
    }
}
