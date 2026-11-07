<?php

namespace App\Livewire\Pulse\Me;

use App\Livewire\Pulse\Me\Concerns\HasTags;
use App\Models\PulseProfile;
use App\Services\Pulse\Entities\MediaService;
use App\Services\Pulse\Entities\ProfileService;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * "Me, Profile": what other people may read about you once they are allowed to (see ProfileService::card).
 * Everything is written through ProfileService; this component only gathers and shows.
 */
class ProfileEditor extends Component
{
    use HasTags;
    use WithFileUploads;

    public string $headline = '';

    public string $bio = '';

    public ?string $experienceYears = null;

    public string $kind = 'individual';

    /** @var list<string> */
    public array $skills = [];

    /** @var list<string> */
    public array $interests = [];

    /** @var list<string> */
    public array $languages = [];

    /** @var list<string> */
    public array $achievements = [];

    /** @var list<array{title: string, org: string, from_year: ?string, to_year: ?string, current: bool, hide: bool}> */
    public array $jobs = [];

    public $avatar = null;

    public ?string $avatarError = null;

    public function mount(): void
    {
        $profile = app(ProfileService::class)->profileFor(auth()->user());

        $this->headline = (string) $profile->headline;
        $this->bio = (string) $profile->bio;
        $this->experienceYears = $profile->experience_years === null ? null : (string) $profile->experience_years;
        $this->kind = $profile->kind ?: 'individual';
        $this->skills = array_values((array) $profile->skills);
        $this->interests = array_values((array) $profile->interests);
        $this->languages = array_values((array) $profile->languages);
        $this->achievements = array_values((array) $profile->achievements);
        $this->jobs = array_values(array_map(fn ($j) => [
            'title' => (string) ($j['title'] ?? ''),
            'org' => (string) ($j['org'] ?? ($j['employer'] ?? '')),
            'from_year' => isset($j['from_year']) ? (string) $j['from_year'] : null,
            'to_year' => isset($j['to_year']) ? (string) $j['to_year'] : null,
            'current' => (bool) ($j['current'] ?? false),
            'hide' => (bool) ($j['hide_from_employer'] ?? false),
        ], (array) $profile->work_history));
    }

    #[Computed]
    public function profile(): PulseProfile
    {
        return app(ProfileService::class)->profileFor(auth()->user());
    }

    public function avatarUrl(): ?string
    {
        $id = $this->profile->avatar_media_id;

        return $id ? app(MediaService::class)->url((int) $id, 'medium') : null;
    }

    public function addAchievement(): void
    {
        if (count($this->achievements) < 10) {
            $this->achievements[] = '';
        }
    }

    public function removeAchievement(int $i): void
    {
        unset($this->achievements[$i]);
        $this->achievements = array_values($this->achievements);
    }

    public function addJob(): void
    {
        if (count($this->jobs) < 8) {
            $this->jobs[] = ['title' => '', 'org' => '', 'from_year' => null, 'to_year' => null, 'current' => false, 'hide' => false];
        }
    }

    public function removeJob(int $i): void
    {
        unset($this->jobs[$i]);
        $this->jobs = array_values($this->jobs);
    }

    public function updatedAvatar(): void
    {
        $this->avatarError = null;

        try {
            $this->validate(['avatar' => ['image', 'max:5120', 'mimes:jpg,jpeg,png,webp']], [], ['avatar' => 'photo']);

            $user = auth()->user();
            $media = app(MediaService::class);
            $old = $this->profile->avatar_media_id;
            $id = $media->store($user, $this->avatar, 'avatar');
            app(ProfileService::class)->setAvatar($user, $id);

            if ($old) {
                $media->delete($user, (int) $old);
            }
        } catch (ValidationException $e) {
            $this->avatarError = collect($e->errors())->flatten()->first();
        } finally {
            $this->avatar = null;
            unset($this->profile);
        }
    }

    public function removeAvatar(): void
    {
        $user = auth()->user();
        $old = $this->profile->avatar_media_id;
        app(ProfileService::class)->setAvatar($user, null);

        if ($old) {
            app(MediaService::class)->delete($user, (int) $old);
        }

        unset($this->profile);
    }

    public function save(): void
    {
        $jobs = collect($this->jobs)
            ->map(fn ($j) => array_map(fn ($v) => is_string($v) ? trim($v) : $v, $j))
            ->filter(fn ($j) => $j['title'] !== '' || $j['org'] !== '')
            ->values();

        $this->validate([
            'headline' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:1500'],
            'experienceYears' => ['nullable', 'integer', 'min:0', 'max:60'],
            'kind' => ['in:individual,professional'],
            'achievements.*' => ['nullable', 'string', 'max:160'],
            'jobs.*.title' => ['nullable', 'string', 'max:80'],
            'jobs.*.org' => ['nullable', 'string', 'max:80'],
            'jobs.*.from_year' => ['nullable', 'integer', 'min:1950', 'max:'.now()->year],
            'jobs.*.to_year' => ['nullable', 'integer', 'min:1950', 'max:'.now()->year],
        ], [
            'jobs.*.title.max' => 'A job title is 80 characters at most.',
            'jobs.*.from_year.integer' => 'Use a year like 2019.',
            'jobs.*.to_year.integer' => 'Use a year like 2023.',
        ]);

        foreach ($jobs as $i => $j) {
            if ($j['title'] === '') {
                $this->addError("jobs.{$i}.title", 'Give each job a title.');

                return;
            }

            if (! $j['current'] && filled($j['from_year']) && filled($j['to_year']) && (int) $j['to_year'] < (int) $j['from_year']) {
                $this->addError("jobs.{$i}.to_year", 'The end year comes after the start year.');

                return;
            }
        }

        $service = app(ProfileService::class);
        $user = auth()->user();

        $rows = $jobs->map(fn ($j) => [
            'title' => $j['title'],
            'employer' => $j['org'] !== '' ? $j['org'] : null,
            'hide_from_employer' => (bool) ($j['hide'] ?? false),
            'from_year' => filled($j['from_year']) ? (int) $j['from_year'] : null,
            'to_year' => ($j['current'] || blank($j['to_year'])) ? null : (int) $j['to_year'],
            'current' => (bool) $j['current'],
        ])->all();

        try {
            $service->update($user, [
                'headline' => $this->headline,
                'bio' => $this->bio,
                'kind' => $this->kind,
                'experience_years' => filled($this->experienceYears) ? (int) $this->experienceYears : null,
                'skills' => $this->skills,
                'interests' => $this->interests,
                'languages' => $this->languages,
                'achievements' => array_values(array_filter($this->achievements, fn ($a) => trim((string) $a) !== '')),
                'work_history' => $rows,
            ]);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        unset($this->profile);
        $this->dispatch('toast', message: 'Profile saved.', kind: 'good');
    }

    public function render(): View
    {
        return view('livewire.pulse.me.profile-editor');
    }
}
