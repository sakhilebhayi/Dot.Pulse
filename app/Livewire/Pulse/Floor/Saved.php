<?php

namespace App\Livewire\Pulse\Floor;

use App\Livewire\Pulse\Floor\Concerns\ActsOnBeats;
use App\Livewire\Pulse\Floor\Support\BeatPresenter;
use App\Services\Pulse\Discovery\Floor;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Saved: the beats a person kept, newest save first, with a filter by kind. Things that have since gone (closed,
 * removed, blocked) are left out by the service, not shown greyed.
 */
class Saved extends Component
{
    use ActsOnBeats;

    public const KINDS = ['offer' => 'Offers', 'need' => 'Needs', 'role' => 'Roles', 'talent' => 'People', 'ask' => 'Asks', 'event' => 'Events', 'door' => 'Doors'];

    #[Url(as: 'kind', except: '')]
    public string $kind = '';

    public function mount(Floor $floor): void
    {
        $this->beats = app(BeatPresenter::class)->many($floor->savedList(auth()->user()), auth()->user());

        if (! isset(self::KINDS[$this->kind])) {
            $this->kind = '';
        }
    }

    protected function afterUnsave(int $signalId): void
    {
        $this->forget($signalId);
    }

    public function render(): View
    {
        $counts = collect($this->beats)->countBy('kind');

        $tabs = ['' => ['label' => 'All', 'href' => route('saved'), 'count' => count($this->beats)]];
        foreach (self::KINDS as $key => $label) {
            if (($counts[$key] ?? 0) > 0) {
                $tabs[$key] = ['label' => $label, 'href' => route('saved', ['kind' => $key]), 'count' => $counts[$key]];
            }
        }

        $shown = $this->kind === '' ? $this->beats : array_values(array_filter($this->beats, fn ($b) => $b['kind'] === $this->kind));

        return view('livewire.pulse.floor.saved', ['tabs' => $tabs, 'shown' => $shown]);
    }
}
