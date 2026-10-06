<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare Turnstile: the browser solves a (usually invisible) challenge and sends its one-use token as
 * `turnstile_token`; we ask Cloudflare whether it's genuine. Off while TURNSTILE_SECRET_KEY is empty
 * (local, CI), so it can be switched on without code changes. Fails closed when Cloudflare can't be reached.
 */
class Turnstile implements ValidationRule
{
    /** Runs even when the token is missing. */
    public bool $implicit = true;

    public const FAILED = "We couldn't check that you're a person. Reload the page and try again.";

    public const UNREACHABLE = "We couldn't check that you're a person just now. Please try again in a moment.";

    public function __construct(private readonly ?string $ip = null) {}

    public static function enabled(): bool
    {
        return (string) config('services.turnstile.secret') !== '';
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::enabled()) {
            return;
        }
        if (! is_string($value) || $value === '' || strlen($value) > 2048) {
            $fail(self::FAILED);

            return;
        }

        try {
            $answer = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array_filter([
                'secret' => config('services.turnstile.secret'),
                'response' => $value,
                'remoteip' => $this->ip,
            ]));
        } catch (Throwable) {
            $fail(self::UNREACHABLE);

            return;
        }

        if (! $answer->successful()) {
            $fail(self::UNREACHABLE);
        } elseif ($answer->json('success') !== true) {
            $fail(self::FAILED);
        }
    }
}
