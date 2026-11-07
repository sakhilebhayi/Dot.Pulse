<?php

namespace App\Livewire\Pulse\Me;

use App\Livewire\Pulse\Me\Concerns\PicksPlace;
use App\Models\PulseProfile;
use App\Services\Pulse\Entities\ProfileService;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/** "Me, Settings": who may contact you first, where you are, how far you look, and the theme. */
class Settings extends Component
{
    use PicksPlace;

    public string $contactPolicy = 'context';

    public bool $showPlace = false;

    public string $reach = 'region';

    public ?string $problem = null;

    public function mount(): void
    {
        $profile = app(ProfileService::class)->profileFor(auth()->user());

        $this->contactPolicy = $profile->contact_policy ?: 'context';
        $this->showPlace = (bool) $profile->show_place;
        $this->reach = $profile->reach ?: 'region';
        $this->placeId = $profile->place_id;
    }

    public function updatedContactPolicy(string $value): void
    {
        $this->attempt(fn () => app(ProfileService::class)->setContactPolicy(auth()->user(), $value), 'Who can contact you is updated.');
    }

    public function updatedShowPlace(): void
    {
        $this->attempt(fn () => app(ProfileService::class)->update(auth()->user(), ['show_place' => $this->showPlace]), 'Saved.');
    }

    public function updatedReach(): void
    {
        $this->attempt(fn () => app(ProfileService::class)->update(auth()->user(), ['reach' => $this->reach]), 'Saved.');
    }

    protected function placeChanged(): void
    {
        $this->attempt(fn () => app(ProfileService::class)->update(auth()->user(), ['place_id' => $this->placeId]), $this->placeId ? 'Place saved.' : 'Place removed.');
    }

    private function attempt(callable $do, string $done): void
    {
        $this->problem = null;

        try {
            $do();
            $this->dispatch('toast', message: $done, kind: 'good');
        } catch (ValidationException $e) {
            $this->problem = collect($e->errors())->flatten()->first();
        }
    }

    public function profile(): PulseProfile
    {
        return app(ProfileService::class)->profileFor(auth()->user());
    }

    public function render(): View
    {
        return view('livewire.pulse.me.settings');
    }
}
