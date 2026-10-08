<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Telegram Gateway refused or couldn't be reached for a reason that is ours to fix (token, balance, flood
 * limits, outage), not the customer's number. Reported to Sentry, so the owner gets a Telegram alert with
 * Telegram's own error code; the customer only sees "Telegram codes aren't available right now".
 */
class TelegramGatewayProblem extends RuntimeException
{
    public static function refused(string $error): self
    {
        $hint = match (true) {
            str_contains($error, 'BALANCE') => ' Top up the balance at gateway.telegram.org (codes to your own number are free).',
            str_contains($error, 'TOKEN') => ' Check TELEGRAM_GATEWAY_TOKEN on the server (gateway.telegram.org → Settings → Copy Token).',
            default => ' Check the account at gateway.telegram.org.',
        };

        return new self("Telegram Gateway refused a code: {$error}.{$hint}");
    }

    public static function unexpected(int $status, string $contentType, string $url): self
    {
        return new self("Telegram Gateway gave an unexpected answer (HTTP {$status}, {$contentType}) from {$url}. The API address should be https://gatewayapi.telegram.org (TELEGRAM_GATEWAY_URL is only for local testing).");
    }

    public static function unreachable(string $reason): self
    {
        return new self("Telegram Gateway could not be reached: {$reason}");
    }
}
