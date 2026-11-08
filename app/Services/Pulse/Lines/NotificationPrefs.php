<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseNotificationPref;
use App\Models\User;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/**
 * What a person wants to hear, and how. Categories: lines (conversations, requests, quotes), matches, outcomes
 * (confirmations and reviews), system (safety and account), digest (the daily summary). Channels: in_app, email.
 * Email can be instant, daily, weekly or off, and respects quiet hours. Lines, outcomes and system are
 * transactional: their in app notification is always written; only the email follows the preferences.
 */
class NotificationPrefs
{
    public const CATEGORIES = ['lines', 'matches', 'outcomes', 'system', 'digest'];

    public const CHANNELS = ['in_app', 'email'];

    public const TRANSACTIONAL = ['lines', 'outcomes', 'system'];

    public const FREQUENCIES = ['instant', 'daily', 'weekly', 'off'];

    /**
     * The person's stored preferences, or the private defaults as an unsaved model. Reading never writes a row, so
     * looking at a settings page or the consent ledger can never opt anyone in to anything.
     */
    public function get(User $u): PulseNotificationPref
    {
        return PulseNotificationPref::query()->where('user_id', $u->id)->first()
            ?? new PulseNotificationPref(['user_id' => $u->id] + $this->defaults());
    }

    public function update(User $u, array $data): PulseNotificationPref
    {
        $pref = $this->get($u);
        $changes = [];

        if (array_key_exists('channels', $data)) {
            $channels = $pref->channels ?? [];
            foreach (self::CHANNELS as $channel) {
                if (array_key_exists($channel, (array) $data['channels'])) {
                    $channels[$channel] = (bool) $data['channels'][$channel];
                }
            }
            $changes['channels'] = $channels;
        }

        if (array_key_exists('categories', $data)) {
            $categories = $this->normaliseCategories($pref->categories ?? []);
            foreach ((array) $data['categories'] as $category => $value) {
                if (! in_array($category, self::CATEGORIES, true)) {
                    continue;
                }
                $value = is_array($value) ? $value : array_fill_keys(self::CHANNELS, (bool) $value);
                foreach (self::CHANNELS as $channel) {
                    if (array_key_exists($channel, $value)) {
                        $categories[$category][$channel] = (bool) $value[$channel];
                    }
                }
            }
            $changes['categories'] = $categories;
        }

        if (array_key_exists('frequency', $data)) {
            $this->assertIn('frequency', $data['frequency'], self::FREQUENCIES);
            $changes['frequency'] = $data['frequency'];
        }

        if (array_key_exists('digest', $data)) {
            $changes['digest'] = (bool) $data['digest'];
        }

        if (array_key_exists('digest_hour', $data)) {
            $this->assertClock('digest_hour', $data['digest_hour']);
            $changes['digest_hour'] = $data['digest_hour'];
        }

        if (array_key_exists('timezone', $data)) {
            if (! in_array($data['timezone'], DateTimeZone::listIdentifiers(), true)) {
                throw ValidationException::withMessages(['timezone' => 'Pick a time zone from the list.']);
            }
            $changes['timezone'] = $data['timezone'];
        }

        foreach (['quiet_start', 'quiet_end'] as $key) {
            if (array_key_exists($key, $data)) {
                if ($data[$key] !== null && $data[$key] !== '') {
                    $this->assertClock($key, $data[$key]);
                }
                $changes[$key] = $data[$key] === '' ? null : $data[$key];
            }
        }

        $pref->fill($changes)->save();
        AuditLog::record($u, 'prefs.update', $pref, ['keys' => array_keys($changes)]);

        return $pref;
    }

    /**
     * Whether this category may reach the person on this channel. Reads the stored row; a person who never opened
     * the settings gets the defaults without a row being written.
     */
    public function allowed(User $u, string $category, string $channel): bool
    {
        if (! in_array($category, self::CATEGORIES, true) || ! in_array($channel, self::CHANNELS, true)) {
            return false;
        }

        $pref = PulseNotificationPref::query()->where('user_id', $u->id)->first();
        $channels = $pref?->channels ?? $this->defaults()['channels'];
        $categories = $this->normaliseCategories($pref?->categories ?? []);

        if (! ($channels[$channel] ?? true)) {
            return false;
        }

        return (bool) ($categories[$category][$channel] ?? false);
    }

    /** How email is delivered for this person: instant, daily, weekly or off. */
    public function frequency(User $u): string
    {
        return PulseNotificationPref::query()->where('user_id', $u->id)->value('frequency') ?? 'instant';
    }

    /** True inside the person's quiet hours (in their own time zone). */
    public function quietNow(User $u): bool
    {
        $pref = PulseNotificationPref::query()->where('user_id', $u->id)->first();

        if ($pref === null || ! $pref->quiet_start || ! $pref->quiet_end) {
            return false;
        }

        $now = now($pref->timezone ?: 'Africa/Johannesburg');
        $minutes = $now->hour * 60 + $now->minute;
        [$sh, $sm] = array_map('intval', explode(':', $pref->quiet_start));
        [$eh, $em] = array_map('intval', explode(':', $pref->quiet_end));
        $start = $sh * 60 + $sm;
        $end = $eh * 60 + $em;

        if ($start === $end) {
            return false;
        }

        return $start < $end ? ($minutes >= $start && $minutes < $end) : ($minutes >= $start || $minutes < $end);
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'channels' => ['in_app' => true, 'email' => true],
            'categories' => $this->normaliseCategories([]),
            'frequency' => 'instant',
            'digest' => false,
            'digest_hour' => '07:30',
            'timezone' => 'Africa/Johannesburg',
        ];
    }

    /**
     * What each category does when the person has not chosen. In app is on everywhere; email is on only for the
     * transactional lines and outcomes. Matches and the digest never email until the person turns them on.
     *
     * @return array<string, array{in_app: bool, email: bool}>
     */
    public function categoryDefaults(): array
    {
        return [
            'lines' => ['in_app' => true, 'email' => true],
            'outcomes' => ['in_app' => true, 'email' => true],
            'system' => ['in_app' => true, 'email' => false],
            'matches' => ['in_app' => true, 'email' => false],
            'digest' => ['in_app' => true, 'email' => false],
        ];
    }

    /** @return array<string, array{in_app: bool, email: bool}> */
    private function normaliseCategories(array $stored): array
    {
        $defaults = $this->categoryDefaults();
        $out = [];
        foreach (self::CATEGORIES as $category) {
            $value = $stored[$category] ?? null;
            $out[$category] = match (true) {
                is_array($value) => [
                    'in_app' => (bool) ($value['in_app'] ?? $defaults[$category]['in_app']),
                    'email' => (bool) ($value['email'] ?? $defaults[$category]['email']),
                ],
                $value === null => $defaults[$category],
                default => ['in_app' => (bool) $value, 'email' => (bool) $value],
            };
        }

        return $out;
    }

    private function assertIn(string $field, mixed $value, array $allowed): void
    {
        if (! in_array($value, $allowed, true)) {
            throw ValidationException::withMessages([$field => 'That is not one of the choices.']);
        }
    }

    private function assertClock(string $field, mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            throw ValidationException::withMessages([$field => 'Use a time like 07:30.']);
        }
    }
}
