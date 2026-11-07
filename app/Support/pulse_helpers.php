<?php

use Illuminate\Support\Facades\Route;

if (! function_exists('pulse_route')) {
    /**
     * A URL for a named route, or '#' when the route is not registered (yet).
     *
     * The shell links to screens other agents are still building. A missing route must never crash a
     * page, so every shell and kit link goes through this helper. '#' is a real, harmless href.
     */
    function pulse_route(string $name, mixed $parameters = [], bool $absolute = true): string
    {
        if (! Route::has($name)) {
            return '#';
        }

        try {
            return route($name, $parameters, $absolute);
        } catch (\Throwable) {
            return '#';
        }
    }
}

if (! function_exists('pulse_route_is')) {
    /** True when the current route name matches any of the patterns. False outside a routed request. */
    function pulse_route_is(string ...$patterns): bool
    {
        $request = request();

        return $request !== null && $request->route() !== null && $request->routeIs(...$patterns);
    }
}

if (! function_exists('pulse_infodot_url')) {
    /** The InfoDot home page, derived from the redeem endpoint so one setting controls both. */
    function pulse_infodot_url(): string
    {
        $redeem = (string) config('ecosystem.infodot.redeem_url', 'https://infodot.co.za/api/ecosystem/redeem');
        $scheme = parse_url($redeem, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($redeem, PHP_URL_HOST) ?: 'infodot.co.za';

        return $scheme.'://'.$host;
    }
}

if (! function_exists('pulse_initials')) {
    /** Up to two initials from a display name. Never empty. */
    function pulse_initials(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = $parts[0] ?? '?';
        $last = count($parts) > 1 ? $parts[count($parts) - 1] : '';

        return mb_strtoupper(mb_substr($first, 0, 1).($last !== '' ? mb_substr($last, 0, 1) : ''));
    }
}

if (! function_exists('pulse_route_first')) {
    /** The URL of the first registered route in the list, or '#'. The shell uses it to point at a new screen once it exists. */
    function pulse_route_first(array $names, mixed $parameters = []): string
    {
        foreach ($names as $name) {
            if (Route::has($name)) {
                return pulse_route($name, $parameters);
            }
        }

        return '#';
    }
}
