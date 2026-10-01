<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureCustomerEmailIsVerified;
use App\Http\Middleware\EnsureStaffTwoFactorEnabled;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Sentry\Laravel\Integration;

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
        $middleware->throttleApi('api');

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
    })->create();
