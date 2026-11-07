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
        } catch (Throwable) {
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

if (! function_exists('pulse_review_note')) {
    /** The one sentence about what happens to something held for a person: who looks, in what order, how long, how you hear. */
    function pulse_review_note(): string
    {
        return 'A person checks held listings in the order they arrive, aiming for within '.config('pulse.moderation.review_target', 'one working day').'. You are told the moment it is decided.';
    }
}

if (! function_exists('pulse_my_businesses')) {
    /**
     * The business pages the signed-in person belongs to, for the rail and the account menu: name, slug, their role.
     * One small query per request, and nothing at all for a visitor or for someone who runs no page.
     *
     * @return list<array{name: string, slug: string, role: string}>
     */
    function pulse_my_businesses(): array
    {
        $id = auth()->id();

        if ($id === null) {
            return [];
        }

        // Held on the request, so a long-lived process (a test run, Octane) never serves one person's pages to the next.
        $request = request();
        $key = 'pulse.businesses.'.$id;

        if ($request->attributes->has($key)) {
            return $request->attributes->get($key);
        }

        try {
            $rows = \App\Models\PulseEntityMember::query()
                ->join('pulse_entities as e', 'e.id', '=', 'pulse_entity_members.entity_id')
                ->where('pulse_entity_members.user_id', $id)
                ->whereNull('e.deleted_at')
                ->orderBy('e.name')
                ->limit(8)
                ->get(['e.name', 'e.slug', 'pulse_entity_members.role'])
                ->map(fn ($r) => ['name' => (string) $r->name, 'slug' => (string) $r->slug, 'role' => (string) $r->role])
                ->all();
        } catch (Throwable) {
            $rows = [];
        }

        $request->attributes->set($key, $rows);

        return $rows;
    }
}
