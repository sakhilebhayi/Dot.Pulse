<?php

namespace App\Services\Pulse\Lines;

use App\Models\User;
use App\Support\PulseThrottle;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per person rate limits for the lines actions, keyed by the acting user (the services are called outside the
 * request's auth user in jobs and tests). Same keys and error as App\Support\PulseThrottle. Limits come from
 * config('pulse.lines.rate_limits'): messages 30 a minute; first contacts are bounded by the knock budget as well.
 */
class Throttle
{
    public static function hit(User $by, string $action): void
    {
        $max = (int) config("pulse.lines.rate_limits.{$action}", 0);

        if ($max <= 0) {
            return;
        }

        $key = "pulse:{$action}:".$by->id;

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw PulseThrottle::exception(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, PulseThrottle::WINDOW_SECONDS);
    }
}
