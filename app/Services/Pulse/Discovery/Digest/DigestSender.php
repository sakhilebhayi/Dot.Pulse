<?php

namespace App\Services\Pulse\Discovery\Digest;

use App\Models\PulseNotificationPref;
use App\Models\User;
use App\Services\Pulse\Discovery\Support\Audit;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Sends a person's digest if, and only if, they asked for one, it is not their quiet time and there is something
 * real to say. Opt-in by construction: no preference row, no digest.
 *
 * Preferences read (pulse_notification_prefs): digest (bool), frequency (daily|weekly; instant and off mean no
 * digest), digest_hour (HH:MM), timezone, last_digest_at, channels.email (false switches email off), categories
 * digest/match (false switches the digest off), and quiet hours: the Lines module's NotificationPrefs::quietNow()
 * when it is installed, otherwise channels.quiet_hours or categories.quiet_hours as {start, end} (HH:MM).
 */
class DigestSender
{
    public function __construct(private readonly DigestBuilder $builder) {}

    /** Whether this person wants a digest at all (ignores the clock). */
    public function enabled(?PulseNotificationPref $prefs): bool
    {
        if ($prefs === null || ! $prefs->digest || ! in_array($prefs->frequency, ['daily', 'weekly'], true)) {
            return false;
        }

        if (($prefs->channels['email'] ?? true) === false) {
            return false;
        }

        foreach (['digest', 'match', 'matches'] as $category) {
            if (($prefs->categories[$category] ?? true) === false) {
                return false;
            }
        }

        return true;
    }

    /** Whether it is time for this person's digest: their hour has come, within the grace window, once per slot. */
    public function due(User $u, ?CarbonInterface $now = null): bool
    {
        $prefs = PulseNotificationPref::where('user_id', $u->id)->first();

        return $prefs !== null && $this->dueFor($prefs, $now ?? now());
    }

    public function dueFor(PulseNotificationPref $prefs, CarbonInterface $now): bool
    {
        if (! $this->enabled($prefs)) {
            return false;
        }

        $local = Carbon::instance($now)->setTimezone($this->timezone($prefs));
        [$h, $m] = array_pad(array_map('intval', explode(':', (string) $prefs->digest_hour)), 2, 0);
        $slot = $local->copy()->setTime(min(23, $h), min(59, $m), 0);

        if ($prefs->frequency === 'weekly' && $local->dayOfWeekIso !== 1) {
            return false; // weekly digests go on Monday
        }

        $grace = (int) config('pulse.digest.grace_hours', 10);

        if ($local->lt($slot) || $local->gt($slot->copy()->addHours($grace))) {
            return false;
        }

        return $prefs->last_digest_at === null || $prefs->last_digest_at->lt($slot);
    }

    /** Build and send. False when not wanted, in quiet hours, already sent for this slot, or nothing real to say. */
    public function send(User $u): bool
    {
        $prefs = PulseNotificationPref::where('user_id', $u->id)->first();

        if (! $this->enabled($prefs) || $this->quietNow($u, $prefs)) {
            return false;
        }

        // One per slot even if called twice.
        if ($prefs->last_digest_at !== null && $prefs->last_digest_at->gt(now()->subHours($prefs->frequency === 'weekly' ? 144 : 20))) {
            return false;
        }

        $digest = $this->builder->build($u);

        if ($digest === null) {
            return false;
        }

        $u->notify(new DigestNotification($digest));

        // The next digest carries only what was published after this one.
        DB::table('pulse_notification_prefs')->where('id', $prefs->id)->update(['last_digest_at' => now()]);
        Audit::record($u, 'pulse.digest.sent', null, ['items' => $digest['count']]);

        return true;
    }

    /** Whether the person asked not to be disturbed right now. */
    public function quietNow(User $u, ?PulseNotificationPref $prefs = null): bool
    {
        $shared = 'App\\Services\\Pulse\\Lines\\NotificationPrefs';

        if (class_exists($shared) && method_exists($shared, 'quietNow')) {
            try {
                return (bool) app($shared)->quietNow($u);
            } catch (\Throwable) {
                // fall through to the stored window
            }
        }

        $prefs ??= PulseNotificationPref::where('user_id', $u->id)->first();
        $window = $prefs?->channels['quiet_hours'] ?? $prefs?->categories['quiet_hours'] ?? null;

        if (! is_array($window) || empty($window['start']) || empty($window['end'])) {
            return false;
        }

        $local = now()->setTimezone($this->timezone($prefs));
        $now = $local->format('H:i');
        $start = (string) $window['start'];
        $end = (string) $window['end'];

        // A window like 21:00 to 07:00 crosses midnight.
        return $start <= $end ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end);
    }

    private function timezone(?PulseNotificationPref $prefs): string
    {
        $tz = (string) ($prefs?->timezone ?? '');

        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'Africa/Johannesburg';
    }
}
