<?php

namespace App\Livewire\Pulse\Floor;

use App\Livewire\Pulse\Floor\Concerns\ActsOnBeats;
use App\Livewire\Pulse\Floor\Support\BeatPresenter;
use App\Models\PulseMatch;
use App\Services\Pulse\Discovery\Floor;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The Floor: one round of at most twenty beats for a mode, then a batch button, then the end.
 *
 *   <livewire:pulse.floor.round mode="trade" />
 *
 * The round comes from Floor::round (matches, diversity, exclusions are all the service's). This component only
 * draws it and acts on it. Beats are marked seen when they have been on screen (the browser says which, through
 * markSeen) and when the next batch is asked for; a seen beat leaves the next round. Because seen beats leave the pool,
 * the next batch is always the first page of what is left, so no cursor is carried between batches.
 */
class Round extends Component
{
    use ActsOnBeats;

    #[Locked]
    public string $mode = 'all';

    /** More beats are waiting in this round. */
    #[Locked]
    public bool $hasMore = false;

    #[Locked]
    public bool $caughtUp = false;

    /** ISO time the next round is ready, set only when caught up. */
    #[Locked]
    public ?string $nextRoundAt = null;

    /** How many beats are waiting beyond the ones on screen. */
    #[Locked]
    public int $waiting = 0;

    /** The person has had matches before (so a finished round is "caught up", not "nothing yet"). */
    #[Locked]
    public bool $everMatched = false;

    public function mount(Floor $floor, string $mode = 'all'): void
    {
        $this->mode = in_array($mode, Floor::MODES, true) ? $mode : 'all';

        $round = $floor->round(auth()->user(), $this->mode);
        $this->beats = app(BeatPresenter::class)->many($round['beats'], auth()->user());
        $this->absorb($round, count($round['beats']));

        $this->everMatched = $this->beats !== [] || PulseMatch::query()->where('user_id', auth()->id())->whereNull('dismissed_at')->exists();
    }

    /** The next batch of the round. */
    public function showNext(Floor $floor): void
    {
        if (! $this->hasMore) {
            return;
        }

        $this->markSeen(array_column($this->beats, 'signal_id'), $floor);

        $round = $floor->round(auth()->user(), $this->mode);
        $known = array_column($this->beats, 'signal_id');
        $fresh = array_values(array_filter(
            app(BeatPresenter::class)->many($round['beats'], auth()->user()),
            fn (array $b) => ! in_array($b['signal_id'], $known, true),
        ));

        $this->beats = array_merge($this->beats, $fresh);
        $this->absorb($round, count($round['beats']));
    }

    /**
     * The browser reports beats that have been on screen. Only beats this round holds can be marked.
     *
     * @param  array<int, int|string>  $signalIds
     */
    public function markSeen(array $signalIds, ?Floor $floor = null): void
    {
        $onScreen = array_map('intval', array_column($this->beats, 'signal_id'));
        $ids = array_values(array_intersect(array_map('intval', $signalIds), $onScreen));

        if ($ids !== []) {
            ($floor ?? app(Floor::class))->markSeen(auth()->user(), $ids);
        }
    }

    /** @param array{next: ?string, caught_up: bool, next_round_at: ?Carbon, counts: array{new: int}} $round */
    private function absorb(array $round, int $batch): void
    {
        $this->hasMore = $round['next'] !== null;
        $this->caughtUp = $round['caught_up'];
        $this->nextRoundAt = $round['next_round_at']?->toIso8601String();
        $this->waiting = max(0, (int) $round['counts']['new'] - $batch);
    }

    public function render(): View
    {
        return view('livewire.pulse.floor.round', [
            'readyAt' => $this->nextRoundAt ? Carbon::parse($this->nextRoundAt)->timezone(config('app.timezone')) : null,
        ]);
    }
}
