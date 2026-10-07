<?php

namespace App\Services\Auth;

use App\Exceptions\TelegramCodeNotSent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram Gateway (https://core.telegram.org/gateway): Telegram delivers our 6-digit code from its own
 * "Verification Codes" chat to the Telegram account of a phone number. We make and check the code
 * ourselves (OtpService), so expiry and the wrong-guess lock are the same as for email codes.
 * About $0.01 per delivered code, prepaid. Off while TELEGRAM_GATEWAY_TOKEN is empty.
 */
class TelegramGateway
{
    private const URL = 'https://gateway.telegram.org/sendVerificationMessage';

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
                ->post(self::URL, [
                    'phone_number' => $phoneE164,
                    'code' => $code,
                    'ttl' => max(30, min(3600, $ttlSeconds)),
                ]);
        } catch (Throwable $e) {
            Log::warning('Telegram Gateway unreachable', ['error' => $e->getMessage()]);
            throw TelegramCodeNotSent::unavailable();
        }

        if ($response->json('ok') === true) {
            return;
        }

        $error = (string) ($response->json('error') ?? 'HTTP_'.$response->status());
        // Errors about the number (invalid, no Telegram account, can't receive codes) are the customer's
        // to fix; anything else (token, balance, flood limits) is ours and is logged for Sentry.
        if (str_contains($error, 'PHONE')) {
            throw TelegramCodeNotSent::number();
        }
        Log::error('Telegram Gateway refused a code', ['error' => $error]);
        throw TelegramCodeNotSent::unavailable();
    }
}
