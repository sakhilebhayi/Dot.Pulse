<?php

namespace App\Http\Middleware;

use App\Models\PulseProfile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `pulse.onboarded`. A signed-in person who has not finished the short welcome flow (18 or over confirmed,
 * onboarded_at set) is sent there once, for any page that carries this middleware. The page they wanted is
 * remembered and they land on it afterwards. Only plain page loads are redirected: form posts, JSON and Livewire
 * calls pass, so nothing in flight is lost.
 *
 * Adopt it on a route or group: ->middleware('pulse.onboarded').
 */
class EnsureOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $request->isMethod('GET') || $request->expectsJson() || $request->routeIs('onboarding')) {
            return $next($request);
        }

        $key = 'pulse.onboarded.'.$user->id;

        if ($request->session()->get($key) === true) {
            return $next($request);
        }

        $done = PulseProfile::query()->where('user_id', $user->id)->whereNotNull('onboarded_at')->exists();

        if ($done) {
            $request->session()->put($key, true);

            return $next($request);
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('onboarding');
    }
}
