<?php

namespace App\Livewire\Pulse\Floor;

use App\Livewire\Pulse\Floor\Support\BeatPresenter;
use App\Livewire\Pulse\Floor\Support\Persona;
use App\Models\PulseDoor;
use App\Models\PulseNeed;
use App\Models\PulseRole;
use App\Models\PulseSave;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The context column beside the Floor: where your door stands, what you have open, how much you saved, and the way
 * to the quiet settings. Real counts only, and no counts of other people (no followers, no likes).
 */
class Context extends Component
{
    #[On('pulse-saved-changed')]
    #[On('pulse-door-changed')]
    public function refresh(): void
    {
        // Re-rendering is the refresh.
    }

    public function render(Persona $persona): View
    {
        $user = auth()->user();

        $door = PulseDoor::query()->open()->where('owner_id', $user->id)->latest('id')->first();

        $roles = PulseRole::query()
            ->where('status', 'live')
            ->whereIn('entity_id', fn ($q) => $q->select('entity_id')->from('pulse_entity_members')->where('user_id', $user->id))
            ->count();

        return view('livewire.pulse.floor.context', [
            'action' => $persona->endAction($user),
            'door' => $door,
            'doorUntil' => $door ? BeatPresenter::until($door->closes_at) : null,
            'needs' => PulseNeed::query()->where('user_id', $user->id)->where('status', 'open')->count(),
            'roles' => $roles,
            'saved' => PulseSave::query()->where('user_id', $user->id)->where('saveable_type', 'signal')->count(),
        ]);
    }
}
