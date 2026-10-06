<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureCustomerEmailIsVerified;
use App\Http\Middleware\EnsureStaffTwoFactorEnabled;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Sentry\Laravel\Integration;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->throttleApi('api');

        // No browser logins or forms here (the API uses Bearer tokens), so web pages such as /docs need no
        // session: visiting them must not write a sessions row or depend on the database.
        $middleware->web(remove: [StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'verified.customer' => EnsureCustomerEmailIsVerified::class,
            'staff.2fa' => EnsureStaffTwoFactorEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Reports unhandled errors to Sentry when SENTRY_LARAVEL_DSN is set (does nothing otherwise).
        // Laravel already skips 4xx exceptions such as validation, 401, 403 and 404.
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // An email that couldn't be sent (mail provider down or refusing) is still reported above,
        // but the customer gets a plain "try again" instead of a 500.
        $exceptions->render(function (TransportExceptionInterface $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => "We couldn't send the email just now. Please try again in a few minutes.",
                ], 503);
            }
        });
    })->create();
