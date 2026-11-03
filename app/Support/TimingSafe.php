<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sign-in and "forgot password" must take as long for an email that has no account as for one that has, or a bot
 * can tell which emails are customers or staff from the response time alone (security review 2026-11-03: unknown
 * emails answered in 0.03 s, known ones in 0.2-0.5 s).
 */
final class TimingSafe
{
    private const DUMMY_KEY = 'auth:timing-safe-dummy-hash';

    /**
     * Whether $plain matches $hash. Always does exactly one password-hash check, also when there is no hash (no such
     * account, or an account without a password), so every answer costs the same.
     */
    public static function check(string $plain, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            Hash::check($plain, self::dummyHash());

            return false;
        }

        return Hash::check($plain, $hash);
    }

    /** A real hash with today's cost settings (made once, remade if the cost changes), never matching any input. */
    private static function dummyHash(): string
    {
        $hash = Cache::rememberForever(self::DUMMY_KEY, fn () => Hash::make(Str::random(40)));
        if (Hash::needsRehash($hash)) {
            Cache::forever(self::DUMMY_KEY, $hash = Hash::make(Str::random(40)));
        }

        return $hash;
    }

    /**
     * Runs $work once the response has been sent (making a code and emailing it takes a few hundred ms). On the live
     * server (FrankenPHP) the answer reaches the visitor first; `php artisan serve` cannot do that, so locally the
     * time still differs. A failure is reported to the developers; the visitor got the same answer either way.
     */
    public static function afterResponse(Closure $work): void
    {
        app()->terminating(function () use ($work) {
            // The server may already have closed the connection (fastcgi_finish_request, which FrankenPHP supports):
            // keep running anyway, so the code email is always sent.
            ignore_user_abort(true);
            try {
                $work();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
