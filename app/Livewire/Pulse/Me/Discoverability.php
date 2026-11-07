<?php

namespace App\Livewire\Pulse\Me;

use App\Models\PulseProfile;
use App\Services\Pulse\Entities\ProfileService;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * "Me, Discoverability": whether employers can find you, what exactly they would see, and whom to hide from.
 * Each choice is saved the moment it is made; there is no save button to forget.
 */
class Discoverability extends Component
{
    public string $mode = 'off';

    /** @var list<string> */
    public array $openTo = [];

    public ?string $problem = null;

    public function mount(): void
    {
        $profile = app(ProfileService::class)->profileFor(auth()->user());

        $this->mode = $profile->discoverability ?: 'off';
        $this->openTo = array_values((array) $profile->open_to);
    }

    public function updatedMode(string $value): void
    {
        $this->problem = null;
        $service = app(ProfileService::class);

        try {
            $service->setDiscoverability(auth()->user(), $value);
            $this->dispatch('toast', message: match ($value) {
                'off' => 'Employers can no longer find you.',
                'anonymous' => 'Employers can find you without your name.',
                default => 'Employers can find you by name.',
            }, kind: 'good');
        } catch (ValidationException $e) {
            $this->problem = collect($e->errors())->flatten()->first();
            $this->mode = $service->profileFor(auth()->user())->discoverability ?: 'off';
        }
    }

    public function updatedOpenTo(): void
    {
        try {
            app(ProfileService::class)->setOpenTo(auth()->user(), $this->openTo);
        } catch (ValidationException $e) {
            $this->problem = collect($e->errors())->flatten()->first();
        }
    }

    /** Hide (or show again) the person from the employer named on one job. */
    public function toggleHide(int $index): void
    {
        $profile = app(ProfileService::class)->profileFor(auth()->user());
        $jobs = array_values((array) $profile->work_history);

        if (! isset($jobs[$index]) || blank($jobs[$index]['org'] ?? null)) {
            return;
        }

        $jobs[$index]['employer'] = $jobs[$index]['org'];
        $jobs[$index]['hide_from_employer'] = ! ($jobs[$index]['hide_from_employer'] ?? false);
        $profile->work_history = $jobs;
        $profile->save();

        $this->dispatch('toast', message: $jobs[$index]['hide_from_employer'] ? 'Hidden from '.$jobs[$index]['org'].'.' : 'No longer hidden from '.$jobs[$index]['org'].'.', kind: 'good');
    }

    /**
     * Exactly what an employer sees in anonymous mode: the card for a signed-out viewer, built from your saved
     * profile as if anonymous mode were on. Nothing else is added.
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        $saved = app(ProfileService::class)->profileFor(auth()->user());
        // Raw attributes, not fill(): fill() would run the array casts over values that are already encoded.
        $clone = new PulseProfile;
        $clone->setRawAttributes($saved->getAttributes(), true);
        $clone->exists = true;
        $clone->discoverability = 'anonymous';
        $clone->age_confirmed_at ??= now();

        return app(ProfileService::class)->card($clone, null);
    }

    public function profile(): PulseProfile
    {
        return app(ProfileService::class)->profileFor(auth()->user());
    }

    public function render(): View
    {
        return view('livewire.pulse.me.discoverability');
    }
}
