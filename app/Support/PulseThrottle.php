<?php

namespace App\Support;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-user, per-action rate limiting for the Livewire actions (they bypass
 * route throttle middleware). Limits are attempts per minute from
 * config('pulse.rate_limits'); 0 turns a limit off. A refused action is a
 * real HTTP 429 with Retry-After, which the layout turns into a quiet
 * notice instead of an error page.
 */
class PulseThrottle
{
    public const WINDOW_SECONDS = 60;

    public static function hit(string $action): void
    {
        $max = (int) config("pulse.rate_limits.{$action}", 0);

        if ($max <= 0) {
            return;
        }

        $key = "pulse:{$action}:".(auth()->id() ?? request()->ip());

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw self::exception(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);
    }

    public static function exception(int $retryAfter): ThrottleRequestsException
    {
        return new ThrottleRequestsException(
            'You are doing that too quickly. Try again in '.$retryAfter.' seconds.',
            null,
            ['Retry-After' => (string) $retryAfter],
        );
    }
}
