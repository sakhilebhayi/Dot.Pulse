<?php

namespace App\Livewire\Pulse\Me;

use App\Livewire\Pulse\Me\Concerns\HasTags;
use App\Livewire\Pulse\Me\Concerns\PicksPlace;
use App\Models\PulseProfile;
use App\Services\Pulse\Entities\ProfileService;
use App\Services\Pulse\Lines\NotificationPrefs;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The welcome flow (D25, D24). Seven short steps; only the first is required. Every privacy choice starts on the
 * more private option and a skipped step keeps it. Nothing is saved except the age declaration until the last
 * step, so leaving halfway changes nothing.
 */
class Onboarding extends Component
{
    use HasTags;
    use PicksPlace;

    public const STEPS = ['age', 'you', 'place', 'interests', 'discover', 'contact', 'notify'];

    public const OPEN_TO_LABELS = [
        'work' => 'A job',
        'freelance' => 'Freelance and project work',
        'collab' => 'Collaborators',
        'mentoring' => 'Mentoring, giving or getting',
        'investing' => 'Investors or investing',
        'suppliers' => 'Suppliers and trade partners',
        'learning' => 'Learning, courses and events',
    ];

    #[Locked]
    public int $step = 1;

    public bool $adult = false;

    public string $kind = 'individual';

    /** @var list<string> */
    public array $openTo = [];

    public bool $showPlace = false;

    /** @var list<string> */
    public array $skills = [];

    /** @var list<string> */
    public array $interests = [];

    public string $headline = '';

    public string $discoverability = 'off';

    public string $contactPolicy = 'context';

    public bool $emailConversations = true;

    public bool $emailMatches = false;

    public bool $digest = false;

    public string $digestHour = '07:00';

    public string $timezone = 'Africa/Johannesburg';

    public ?string $problem = null;

    public function mount(): void
    {
        $profile = PulseProfile::query()->where('user_id', auth()->id())->first();

        if ($profile?->onboarded_at !== null) {
            $this->redirectRoute('me.profile', navigate: false);

            return;
        }

        if ($profile) {
            $this->adult = $profile->age_confirmed_at !== null;
            $this->kind = $profile->kind ?: 'individual';
            $this->openTo = array_values((array) $profile->open_to);
            $this->skills = array_values((array) $profile->skills);
            $this->interests = array_values((array) $profile->interests);
            $this->headline = (string) $profile->headline;
            $this->placeId = $profile->place_id;
        }
    }

    public function stepKey(): string
    {
        return self::STEPS[$this->step - 1];
    }

    public function isLast(): bool
    {
        return $this->step === count(self::STEPS);
    }

    public function setTimezone(string $zone): void
    {
        if (in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            $this->timezone = $zone;
        }
    }

    public function back(): void
    {
        $this->problem = null;
        $this->step = max(1, $this->step - 1);
    }

    /** Skip puts this step's choices back to their defaults and moves on. Step one cannot be skipped. */
    public function skip(): void
    {
        if ($this->stepKey() === 'age') {
            return;
        }

        match ($this->stepKey()) {
            'you' => [$this->kind, $this->openTo] = ['individual', []],
            'place' => [$this->placeId, $this->showPlace] = [null, false],
            'interests' => [$this->skills, $this->interests] = [[], []],
            'discover' => [$this->discoverability, $this->headline] = ['off', $this->headline],
            'contact' => $this->contactPolicy = 'context',
            'notify' => [$this->emailConversations, $this->emailMatches, $this->digest] = [true, false, false],
            default => null,
        };

        if ($this->isLast()) {
            $this->finish();

            return;
        }

        $this->problem = null;
        $this->step++;
    }

    public function next(): void
    {
        $this->problem = null;

        if ($this->stepKey() === 'age') {
            $this->validate(['adult' => ['accepted']], ['adult.accepted' => 'Pulse is for people who are 18 or over. Tick the box if that is you.']);
            app(ProfileService::class)->confirmAge(auth()->user());
        }

        if ($this->stepKey() === 'you') {
            $this->validate([
                'kind' => ['in:individual,professional'],
                'openTo' => ['array'],
                'openTo.*' => ['in:'.implode(',', PulseProfile::OPEN_TO)],
            ]);
        }

        if ($this->stepKey() === 'discover') {
            $this->validate([
                'discoverability' => ['in:'.implode(',', PulseProfile::DISCOVERABILITY)],
                'headline' => ['nullable', 'string', 'max:120'],
            ]);

            if ($this->discoverability !== 'off' && (trim($this->headline) === '' || count($this->skills) < 1)) {
                $this->problem = 'To be found you need a headline and at least one skill, so employers have something real to match on. Add them, or keep this set to off.';

                return;
            }
        }

        if ($this->stepKey() === 'contact') {
            $this->validate(['contactPolicy' => ['in:'.implode(',', PulseProfile::CONTACT_POLICIES)]]);
        }

        if ($this->isLast()) {
            $this->finish();

            return;
        }

        $this->step++;
    }

    public function finish(): void
    {
        $user = auth()->user();
        $profiles = app(ProfileService::class);

        if (! $this->adult) {
            $this->step = 1;
            $this->addError('adult', 'Pulse is for people who are 18 or over. Tick the box if that is you.');

            return;
        }

        try {
            $profiles->confirmAge($user);

            $data = [
                'kind' => $this->kind,
                'show_place' => $this->showPlace,
                'skills' => $this->skills,
                'interests' => $this->interests,
                'open_to' => $this->openTo,
                'place_id' => $this->placeId,
            ];

            if (trim($this->headline) !== '') {
                $data['headline'] = $this->headline;
            }

            $profiles->update($user, $data);
            $profiles->setContactPolicy($user, $this->contactPolicy);

            $this->savePreferences();

            if ($this->discoverability !== 'off') {
                $profiles->setDiscoverability($user, $this->discoverability);
            }

            $profiles->completeOnboarding($user, true);
        } catch (ValidationException $e) {
            $this->problem = collect($e->errors())->flatten()->first();
            $this->step = $this->discoverability !== 'off' ? array_search('discover', self::STEPS, true) + 1 : $this->step;

            return;
        }

        // The first Floor should already hold what fits, not wait for the next scheduled run.
        app(\App\Services\Pulse\Discovery\Matcher::class)->refreshAfter($user);

        session()->put('pulse.onboarded.'.$user->id, true);
        $this->redirect(session()->pull('url.intended', pulse_route_first(['floor', 'dashboard'])), navigate: false);
    }

    private function savePreferences(): void
    {
        $user = auth()->user();
        $prefs = app(NotificationPrefs::class);

        $prefs->update($user, [
            'channels' => ['in_app' => true, 'email' => true],
            'categories' => [
                'lines' => ['in_app' => true, 'email' => $this->emailConversations],
                'outcomes' => ['in_app' => true, 'email' => $this->emailConversations],
                'system' => ['in_app' => true, 'email' => true],
                'matches' => ['in_app' => true, 'email' => $this->emailMatches],
                'digest' => ['in_app' => true, 'email' => $this->digest],
            ],
            'frequency' => 'instant',
            'digest' => $this->digest,
            'digest_hour' => $this->digestHour,
            'timezone' => $this->timezone,
        ]);
    }

    /** @return list<string> */
    public function digestHours(): array
    {
        return array_map(fn ($h) => sprintf('%02d:00', $h), range(5, 22));
    }

    public function render(): View
    {
        return view('livewire.pulse.me.onboarding');
    }
}
