<?php

namespace App\Services\Auth;

use App\Exceptions\TelegramCodeNotSent;
use App\Exceptions\TelegramGatewayProblem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram Gateway (https://core.telegram.org/gateway/api, at gatewayapi.telegram.org): Telegram delivers our 6-digit code from its own
 * "Verification Codes" chat to the Telegram account of a phone number. We make and check the code
 * ourselves (OtpService), so expiry and the wrong-guess lock are the same as for email codes.
 * About $0.01 per delivered code, prepaid. Off while TELEGRAM_GATEWAY_TOKEN is empty.
 */
class TelegramGateway
{
    public static function enabled(): bool
    {
        return (string) config('services.telegram_gateway.token') !== '';
    }

    /** @throws TelegramCodeNotSent */
    public function send(string $phoneE164, string $code, int $ttlSeconds): void
    {
        if (! self::enabled()) {
            throw TelegramCodeNotSent::unavailable();
        }

        try {
            $response = Http::timeout(10)
                ->withToken((string) config('services.telegram_gateway.token'))
                ->asJson()
                ->post(rtrim((string) config('services.telegram_gateway.url'), '/').'/sendVerificationMessage', [
                    'phone_number' => $phoneE164,
                    'code' => $code,
                    'ttl' => max(30, min(3600, $ttlSeconds)),
                ]);
        } catch (Throwable $e) {
            Log::warning('Telegram Gateway unreachable', ['error' => $e->getMessage()]);
            report(TelegramGatewayProblem::unreachable($e->getMessage()));
            throw TelegramCodeNotSent::unavailable();
        }

        if ($response->json('ok') === true) {
            return;
        }
        if (! is_array($response->json())) {
            // Not the Gateway's JSON at all (e.g. a web page): the address is wrong, not the customer's number.
            $what = trim((string) strtok((string) $response->header('Content-Type'), ';')) ?: 'no content type';
            Log::error('Telegram Gateway gave an unexpected answer', ['status' => $response->status(), 'content_type' => $what]);
            report(TelegramGatewayProblem::unexpected($response->status(), $what, (string) config('services.telegram_gateway.url')));
            throw TelegramCodeNotSent::unavailable();
        }

        $error = (string) ($response->json('error') ?? 'HTTP_'.$response->status());
        // Errors about the number (invalid, no Telegram account, can't receive codes) are the customer's
        // to fix; anything else (token, balance, flood limits) is ours and is logged for Sentry.
        if (str_contains($error, 'PHONE')) {
            throw TelegramCodeNotSent::number();
        }
        // Logged and reported to Sentry, so the owner's Telegram alert names Telegram's reason (e.g. no balance).
        Log::error('Telegram Gateway refused a code', ['error' => $error]);
        report(TelegramGatewayProblem::refused($error));
        throw TelegramCodeNotSent::unavailable();
    }
}
