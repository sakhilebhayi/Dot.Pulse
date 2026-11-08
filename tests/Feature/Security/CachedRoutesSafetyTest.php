<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * Production runs `php artisan route:cache`. A cached route file is not loaded at request time, so anything a route
 * file registers as a side effect (a middleware alias, a rate limiter, a binding) is simply absent there while every
 * test, which does load the file, still passes. This is how every signed-in page answered 500 on 2026-11-08.
 */
class CachedRoutesSafetyTest extends TestCase
{
    public function test_route_files_register_nothing_as_a_side_effect(): void
    {
        foreach (glob(base_path('routes/*.php')) as $file) {
            $source = file_get_contents($file);

            foreach (['aliasMiddleware(', 'RateLimiter::for(', 'Route::pattern(', 'Route::bind(', 'Route::model(', 'Gate::define('] as $needle) {
                $this->assertStringNotContainsString($needle, $source, basename($file).' registers something with '.$needle.' that a cached route file would never run. Register it in bootstrap/app.php or a service provider.');
            }
        }
    }

    public function test_every_middleware_alias_the_routes_use_is_registered_outside_the_route_files(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
        $providers = collect(glob(app_path('Providers/*.php')))->map(fn ($f) => file_get_contents($f))->implode("\n");

        foreach (['pulse.onboarded'] as $alias) {
            $this->assertTrue(
                str_contains($bootstrap, "'{$alias}'") || str_contains($providers, "'{$alias}'"),
                "The alias {$alias} is used by routes but is not registered in bootstrap/app.php or a provider."
            );
        }
    }
}
