<?php

namespace App\Livewire\Pulse\Floor;

use App\Livewire\Pulse\Floor\Support\BeatPresenter;
use App\Models\PulseDoor;
use App\Models\PulseEntity;
use App\Services\Pulse\Lines\Access;
use App\Services\Pulse\Lines\Doors;
use App\Services\Pulse\Lines\LineException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Open doors. A door is a public note that says what you are open to, for a day or a week. Strangers may knock once with a
 * short card; you decide who comes in. Knocks arrive in Lines: this page shows only how many have come.
 * A person opens a door for themselves, or for a business page they answer for.
 */
class Door extends Component
{
    public string $purpose = '';

    public string $length = 'day';

    /** 0 is the person; otherwise a business page id. */
    public int $as = 0;

    public ?string $failure = null;

    public function open(Doors $doors): void
    {
        $this->failure = null;
        $this->validate([
            'length' => ['required', 'in:day,week'],
            'purpose' => ['required', 'string', 'min:8', 'max:160'],
        ], [
            'purpose.required' => 'Say what the door is for.',
            'purpose.min' => 'Say what the door is for in at least 8 characters.',
            'purpose.max' => 'Keep it to 160 characters.',
        ], ['purpose' => 'purpose']);

        $user = auth()->user();

        try {
            $owner = $this->as > 0 ? PulseEntity::query()->findOrFail($this->as) : $user;
            $signal = $doors->open($owner, $this->purpose, $this->length, $user);
        } catch (LineException $e) {
            $this->failure = $e->getMessage();

            return;
        } catch (AuthorizationException $e) {
            $this->failure = $e->getMessage() ?: 'You cannot open that door.';

            return;
        }

        $this->reset(['purpose']);
        $this->dispatch('pulse-door-changed');
        $this->dispatch('toast', message: 'Your door is open until '.BeatPresenter::until($signal->expires_at).'.', kind: 'good');
    }

    public function close(int $doorId, Doors $doors): void
    {
        $door = PulseDoor::query()->open()->find($doorId);

        if ($door === null) {
            return;
        }

        try {
            $doors->close(auth()->user(), $door);
        } catch (AuthorizationException) {
            return;
        }

        $this->dispatch('pulse-door-changed');
        $this->dispatch('toast', message: 'Door closed. No new knocks.');
    }

    public function render(): View
    {
        $user = auth()->user();

        $pages = PulseEntity::query()
            ->where('status', 'live')
            ->whereIn('id', fn ($q) => $q->select('entity_id')->from('pulse_entity_members')->where('user_id', $user->id)->whereIn('role', Access::RESPONDER_ROLES))
            ->orderBy('name')
            ->get(['id', 'name']);

        $doors = PulseDoor::query()->open()
            ->where(fn ($q) => $q->where('owner_id', $user->id)->orWhereIn('entity_id', $pages->pluck('id')->all() ?: [0]))
            ->latest('id')
            ->get();

        return view('livewire.pulse.floor.door', [
            'pages' => $pages,
            'doors' => $doors,
            'names' => $pages->pluck('name', 'id'),
            'until' => fn ($d) => BeatPresenter::until($d->closes_at),
        ]);
    }
}
