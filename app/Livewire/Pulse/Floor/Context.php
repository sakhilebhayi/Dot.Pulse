<?php

namespace App\Livewire\Pulse\Floor;

use App\Livewire\Pulse\Floor\Support\BeatPresenter;
use App\Livewire\Pulse\Floor\Support\Persona;
use App\Models\PulseConversation;
use App\Models\PulseDoor;
use App\Models\PulseNeed;
use App\Models\PulseRole;
use App\Models\PulseSave;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Your desk, under the round: where your door stands, what is waiting in Lines and Requests, what you have open, how much
 * you saved, and the way to the quiet settings. One label/value row each. Real counts only, and no counts of other people (no followers, no likes).
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

        // The same two counts the shell badge polls (PulseCheckController), read here so the desk never shows a stale or empty figure.
        $pages = PulseConversation::responderEntityIds($user);

        return view('livewire.pulse.floor.context', [
            'lines' => (int) PulseConversation::query()->unreadFor($user, [PulseConversation::OPEN], $pages)->count(),
            'requests' => (int) PulseConversation::query()->awaitingAnswerFrom($user, $pages)->count(),
            'action' => $persona->endAction($user),
            'door' => $door,
            'doorUntil' => $door ? BeatPresenter::until($door->closes_at) : null,
            'needs' => PulseNeed::query()->where('user_id', $user->id)->where('status', 'open')->count(),
            'roles' => $roles,
            'saved' => PulseSave::query()->where('user_id', $user->id)->where('saveable_type', 'signal')->count(),
        ]);
    }
}
