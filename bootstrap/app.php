<?php

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Http\Middleware\EnsureOnboarded;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // R-50: the broadcasting auth route carries the entitlement check (the same routes as withRouting(channels:)).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', EnsureCompanyEntitled::class]])
    ->withMiddleware(function (Middleware $middleware): void {
        // Security headers and the nonce CSP on every response, including errors and the media route.
        $middleware->append(SecurityHeaders::class);

        // Aliases live here, not in routes/web.php: production runs with cached routes (`route:cache`), and a cached
        // route file is never loaded, so an alias defined there is missing and every page behind it answers 500.
        $middleware->alias([
            'pulse.onboarded' => EnsureOnboarded::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
