<?php

namespace App\Livewire\Pulse\Me;

use App\Models\PulseNotificationPref;
use App\Services\Pulse\Lines\NotificationPrefs;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * "Me, Notifications": what reaches you and how. Reads and writes only through NotificationPrefs. Viewing this page
 * never creates a row; the first save does. Conversations, outcomes and safety messages always appear in the app
 * (the service treats them as transactional), so only their email is a choice.
 */
class NotificationSettings extends Component
{
    public const LABELS = [
        'lines' => ['Conversations', 'Requests, replies and quotes on your lines.'],
        'outcomes' => ['Outcomes and reviews', 'When the other side confirms a result, or a review opens.'],
        'system' => ['Safety and account', 'Things you need to know about your account. Email for these cannot be turned off.'],
        'matches' => ['Matches', 'New things on the Floor that fit you.'],
    ];

    /** @var array<string, bool> */
    public array $inApp = [];

    /** @var array<string, bool> */
    public array $email = [];

    public string $frequency = 'instant';

    public bool $digest = false;

    public string $digestHour = '07:30';

    public string $timezone = 'Africa/Johannesburg';

    public ?string $quietStart = null;

    public ?string $quietEnd = null;

    public function mount(): void
    {
        $service = app(NotificationPrefs::class);
        $pref = PulseNotificationPref::query()->where('user_id', auth()->id())->first();
        $defaults = $service->defaults();
        $categories = $pref?->categories ?? $defaults['categories'];

        foreach (NotificationPrefs::CATEGORIES as $category) {
            $value = $categories[$category] ?? true;
            $value = is_array($value) ? $value : ['in_app' => (bool) $value, 'email' => (bool) $value];
            $this->inApp[$category] = in_array($category, NotificationPrefs::TRANSACTIONAL, true) ? true : (bool) ($value['in_app'] ?? true);
            $this->email[$category] = (bool) ($value['email'] ?? true);
        }

        $this->frequency = $pref?->frequency ?? $defaults['frequency'];
        // The digest is opt-in: no row means off, whatever the service defaults say.
        $this->digest = $pref ? (bool) $pref->digest : false;
        $this->digestHour = $pref?->digest_hour ?? $defaults['digest_hour'];
        $this->timezone = $pref?->timezone ?? $defaults['timezone'];
        $this->quietStart = $pref?->quiet_start;
        $this->quietEnd = $pref?->quiet_end;
    }

    public function useDeviceZone(string $zone): void
    {
        if (in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            $this->timezone = $zone;
        }
    }

    public function clearQuiet(): void
    {
        $this->quietStart = $this->quietEnd = null;
    }

    public function save(): void
    {
        $clock = ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];

        $this->validate([
            'frequency' => ['in:'.implode(',', NotificationPrefs::FREQUENCIES)],
            'digestHour' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'timezone' => ['required', 'in:'.implode(',', DateTimeZone::listIdentifiers())],
            'quietStart' => $clock,
            'quietEnd' => $clock,
        ], [
            'digestHour.regex' => 'Use a time like 07:30.',
            'quietStart.regex' => 'Use a time like 22:00.',
            'quietEnd.regex' => 'Use a time like 06:00.',
            'timezone.in' => 'Pick a time zone from the list.',
        ]);

        if (blank($this->quietStart) !== blank($this->quietEnd)) {
            $this->addError(blank($this->quietStart) ? 'quietStart' : 'quietEnd', 'Set both quiet hours, or clear both.');

            return;
        }

        $categories = [];
        foreach (NotificationPrefs::CATEGORIES as $category) {
            $categories[$category] = [
                'in_app' => in_array($category, NotificationPrefs::TRANSACTIONAL, true) ? true : (bool) ($this->inApp[$category] ?? true),
                'email' => match ($category) {
                    'system' => true,
                    'digest' => $this->digest,
                    default => (bool) ($this->email[$category] ?? false),
                },
            ];
        }

        try {
            app(NotificationPrefs::class)->update(auth()->user(), [
                'categories' => $categories,
                'frequency' => $this->frequency,
                'digest' => $this->digest,
                'digest_hour' => $this->digestHour,
                'timezone' => $this->timezone,
                'quiet_start' => blank($this->quietStart) ? null : $this->quietStart,
                'quiet_end' => blank($this->quietEnd) ? null : $this->quietEnd,
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError(match ($key) {
                    'digest_hour' => 'digestHour', 'quiet_start' => 'quietStart', 'quiet_end' => 'quietEnd', default => $key,
                }, $messages[0]);
            }

            return;
        }

        $this->dispatch('toast', message: 'Notification settings saved.', kind: 'good');
    }

    /** @return list<string> */
    public function hours(): array
    {
        return array_map(fn ($h) => sprintf('%02d:00', $h), range(0, 23));
    }

    /** @return array<string, string> */
    public function zones(): array
    {
        $all = DateTimeZone::listIdentifiers();
        $africa = array_values(array_filter($all, fn ($z) => str_starts_with($z, 'Africa/')));
        $rest = array_values(array_diff($all, $africa));

        return array_combine(array_merge($africa, $rest), array_map(fn ($z) => str_replace('_', ' ', $z), array_merge($africa, $rest)));
    }

    public function render(): View
    {
        return view('livewire.pulse.me.notification-settings');
    }
}
