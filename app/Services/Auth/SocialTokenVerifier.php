<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks that a Google/Facebook access token was issued to *our* app. Without this, a token a user gave
 * to any other app that uses "Sign in with Google/Facebook" could be replayed here to log in as them.
 * Fails closed: no client id configured, provider unreachable or unexpected answer = not ours.
 */
class SocialTokenVerifier
{
    public function issuedToUs(string $provider, string $accessToken): bool
    {
        $clientId = (string) config("services.{$provider}.client_id");
        if ($clientId === '') {
            return false;
        }

        try {
            return match ($provider) {
                'google' => $this->google($clientId, $accessToken),
                'facebook' => $this->facebook($clientId, $accessToken),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    private function google(string $clientId, string $accessToken): bool
    {
        $info = Http::timeout(5)->get('https://oauth2.googleapis.com/tokeninfo', ['access_token' => $accessToken]);

        return $info->successful() && in_array($clientId, [$info->json('aud'), $info->json('azp')], true);
    }

    private function facebook(string $clientId, string $accessToken): bool
    {
        $appToken = $clientId.'|'.config('services.facebook.client_secret');
        $info = Http::timeout(5)->get('https://graph.facebook.com/debug_token', [
            'input_token' => $accessToken,
            'access_token' => $appToken,
        ]);

        return $info->successful() && $info->json('data.is_valid') === true && (string) $info->json('data.app_id') === $clientId;
    }
}
