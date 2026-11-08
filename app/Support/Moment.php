<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The one place that turns a moment, a length of time or a machine code into words a person reads.
 *
 *  - when():  "Just now", "5 min ago", "3 h ago", "Yesterday", "3 days ago" within a week; "12 Nov" (or "12 Nov 2025") after.
 *  - full():  "7 Nov 2026, 22:44", for a title attribute or anywhere the exact moment matters.
 *  - date():  "7 Nov 2026".
 *  - span():  "under a minute", "1 minute", "5 minutes", "1 hour", "2 days". Never "1 minutes".
 *  - plural(): "1 result", "2 results".
 *  - source(): where a fact came from, in words ("InfoDot sign-in"), never a code.
 *
 * Accepts a Carbon, a DateTime or a string (an ISO timestamp from a service or a stored column). Anything it cannot read
 * becomes an empty string, never "null" or a raw value.
 */
final class Moment
{
    private const SOURCES = [
        'infodot_redeem_teams' => 'InfoDot sign-in',
        'infodot_redeem_verification' => 'InfoDot',
        'pulse_account' => 'Your Pulse account',
        'business_claim' => 'What the business told Pulse',
        'pulse_conversations' => 'Lines on Pulse',
        'pulse_records' => 'Pulse records',
    ];

    public static function carbon(mixed $at): ?Carbon
    {
        if ($at === null || $at === '') {
            return null;
        }

        try {
            $c = $at instanceof DateTimeInterface ? Carbon::instance($at) : Carbon::parse((string) $at);
        } catch (\Throwable) {
            return null;
        }

        return $c->timezone((string) config('app.timezone', 'UTC'));
    }

    public static function when(mixed $at): string
    {
        $c = self::carbon($at);

        if ($c === null) {
            return '';
        }

        $now = now();
        $minutes = (int) $c->diffInMinutes($now, false);

        if ($minutes < 0) {
            return self::ahead($c, $now);
        }

        return match (true) {
            $minutes < 1 => 'Just now',
            $minutes < 60 => $minutes.' min ago',
            $c->isToday() => (int) floor($minutes / 60).' h ago',
            $c->isYesterday() => 'Yesterday',
            $c->startOfDay()->diffInDays($now->copy()->startOfDay()) < 7 => (int) $c->copy()->startOfDay()->diffInDays($now->copy()->startOfDay()).' days ago',
            default => $c->format($c->year === $now->year ? 'j M' : 'j M Y'),
        };
    }

    /** A moment still to come: "Today 18:30", "Tomorrow", "Sat 9 Nov", "12 Nov". */
    private static function ahead(Carbon $c, CarbonInterface $now): string
    {
        return match (true) {
            $c->isToday() => 'Today '.$c->format('H:i'),
            $c->isTomorrow() => 'Tomorrow',
            $c->copy()->startOfDay()->diffInDays($now->copy()->startOfDay()) < 7 => $c->format('l'),
            default => $c->format($c->year === $now->year ? 'j M' : 'j M Y'),
        };
    }

    public static function full(mixed $at): string
    {
        return self::carbon($at)?->format('j M Y, H:i') ?? '';
    }

    public static function date(mixed $at): string
    {
        return self::carbon($at)?->format('j M Y') ?? '';
    }

    /** "12 Nov" this year, "12 Nov 2025" otherwise. */
    public static function day(mixed $at): string
    {
        $c = self::carbon($at);

        return $c === null ? '' : $c->format($c->year === now()->year ? 'j M' : 'j M Y');
    }

    /** A length of time in whole seconds, in words. */
    public static function span(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return match (true) {
            $seconds < 60 => 'under a minute',
            $seconds < 3600 => self::plural((int) round($seconds / 60), 'minute'),
            $seconds < 86400 => self::plural((int) round($seconds / 3600), 'hour'),
            default => self::plural((int) round($seconds / 86400), 'day'),
        };
    }

    /** "within 2 hours", or "under a minute" when it is quicker than that. */
    public static function within(int $seconds): string
    {
        return $seconds < 60 ? self::span($seconds) : 'within '.self::span($seconds);
    }

    public static function plural(int $n, string $word, ?string $many = null): string
    {
        return $n.' '.($n === 1 ? $word : ($many ?? $word.'s'));
    }

    public static function source(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        if (isset(self::SOURCES[$code])) {
            return self::SOURCES[$code];
        }

        // An unknown code is a machine word: say nothing rather than print it.
        return preg_match('/^[a-z0-9]+(_[a-z0-9]+)+$/', $code) ? null : $code;
    }
}
