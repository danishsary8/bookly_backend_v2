<?php

namespace App\Services\Payments\Khqr;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Asks the Bakong Open API whether a KHQR was paid (POST /v1/check_transaction_by_md5, KHQR payments design 3.2).
 * The token is sent only in the Authorization header: it is never logged, stored or returned.
 */
class BakongClient
{
    /**
     * @return array<string, mixed>|null the transaction when paid (hash, amount, currency, …), null when not paid yet
     *
     * @throws BakongUnavailable when Bakong cannot answer (no token, refused, server error, timeout)
     */
    public function checkByMd5(string $md5): ?array
    {
        $token = (string) config('payments.bakong.token');
        if ($token === '') {
            throw new BakongUnavailable('token_missing', 'No Bakong token is set.');
        }

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->withToken($token)
                ->acceptJson()
                ->timeout((int) config('payments.bakong.timeout', 5))
                ->post('/v1/check_transaction_by_md5', ['md5' => $md5]);
        } catch (ConnectionException) {
            throw new BakongUnavailable('unreachable', 'Bakong did not answer in time.');
        }

        if ($response->status() === 401) {
            throw new BakongUnavailable('token_invalid', 'Bakong refused the token (expired or wrong).');
        }
        if ($response->status() === 403) {
            // Often "not from a Cambodian IP address" (see the design, section 1).
            throw new BakongUnavailable('blocked', 'Bakong refused this server (403).');
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new BakongUnavailable('error', "Bakong answered HTTP {$response->status()}.");
        }

        $body = $response->json();
        if ((int) ($body['responseCode'] ?? 1) === 0 && is_array($body['data'] ?? null)) {
            return $body['data'];
        }

        return null; // "Transaction could not be found": not paid yet
    }

    /** Days until the token's own `exp` claim (it is a JWT); null when unknown. Read-only, no signature check. */
    public function tokenExpiresAt(): ?\DateTimeImmutable
    {
        $parts = explode('.', (string) config('payments.bakong.token'));
        if (count($parts) !== 3) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        return is_array($claims) && is_numeric($claims['exp'] ?? null) ? (new \DateTimeImmutable)->setTimestamp((int) $claims['exp']) : null;
    }

    private function baseUrl(): string
    {
        return config('payments.bakong.relay_url') ?: config('payments.bakong.api_url');
    }
}
