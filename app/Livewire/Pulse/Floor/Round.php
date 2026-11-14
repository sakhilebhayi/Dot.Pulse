<?php

namespace App\Livewire\Pulse\Floor;

use App\Livewire\Pulse\Floor\Concerns\ActsOnBeats;
use App\Livewire\Pulse\Floor\Support\Arrival;
use App\Livewire\Pulse\Floor\Support\BeatPresenter;
use App\Livewire\Pulse\Floor\Support\Persona;
use App\Services\Pulse\Discovery\Floor;
use App\Support\Moment;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Floor: one round of at most twenty beats for a mode, then a batch button, then the end.
 *
 *   <livewire:pulse.floor.round mode="trade" />
 *
 * The round comes from Floor::round (matches, diversity, exclusions are all the service's). This component only
 * draws it and acts on it. Beats are marked seen when they have been on screen (the browser says which, through
 * markSeen) and when the next batch is asked for. A seen beat leaves the pool of new beats, so the next batch is always
 * the first page of what is left and no cursor is carried between batches. Seen is not done, though: a beat that was
 * seen and not acted on (dismissed, saved, knocked) stays on the round for 24 hours in a quieter "Seen earlier" strip
 * below the new ones (Floor::earlier), so a reload never empties the floor. Those carry `earlier => true`.
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

    /** The person has been shown (or dismissed, or saved) something in this mode before: a finished round is then "caught up", not "nothing yet". */
    #[Locked]
    public bool $everShown = false;

    /** This round sits under the arrival (the Floor itself, not a mode page): its first action is already on screen above it. */
    #[Locked]
    public bool $arrival = false;

    /**
     * The beat shown in the pane beside the rows (signal id). Always one of the beats this round holds: whatever the
     * address or the browser sends is checked against the loaded rows and falls back to the first new beat.
     */
    #[Url(as: 'beat', history: false)]
    public ?int $selected = null;

    public function mount(Floor $floor, string $mode = 'all', bool $arrival = false): void
    {
        $this->mode = in_array($mode, Floor::MODES, true) ? $mode : 'all';
        $this->arrival = $arrival && $this->mode === 'all';

        $presenter = app(BeatPresenter::class);
        $round = $floor->round(auth()->user(), $this->mode);
        $new = $presenter->many($round['beats'], auth()->user());

        $earlier = $floor->earlier(auth()->user(), $this->mode);
        $seen = array_map(fn (array $b) => $b + ['earlier' => true], $presenter->many($earlier['beats'], auth()->user()));

        $this->beats = array_merge($new, $seen);
        $this->absorb($round, count($round['beats']));
        $this->everShown = $new !== [] || $earlier['ever_shown'];
        $this->selected = $this->validSelection($this->selected);
    }

    /** The person picked a row (desktop, no page change). Anything that is not on this round is ignored. */
    public function select(int $signalId): void
    {
        $this->selected = $this->validSelection($signalId, $this->selected);
    }

    /** The selected beat's signal id, when it is on this round, else the first new beat, else the first beat. */
    private function validSelection(?int $wanted, ?int $keep = null): ?int
    {
        $ids = array_map('intval', array_column($this->beats, 'signal_id'));

        if ($wanted !== null && in_array($wanted, $ids, true)) {
            return $wanted;
        }

        if ($keep !== null && in_array($keep, $ids, true)) {
            return $keep;
        }

        foreach ($this->beats as $b) {
            if (empty($b['earlier'])) {
                return (int) $b['signal_id'];
            }
        }

        return $ids[0] ?? null;
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

    /**
     * What the arrival says about this round: a count of what is really here, never a slogan. New beats only (the
     * ones seen earlier are not "in this round"); "on two reasons or more" counts the loaded beats' own reasons.
     *
     * @return array{0: string, 1: string}
     */
    private function headline(bool $firstVisit): array
    {
        $fresh = array_values(array_filter($this->beats, fn ($b) => empty($b['earlier'])));

        if ($firstVisit) {
            return ['Nothing fits yet.', 'Say what you want and Dot.Pulse introduces the businesses and people that fit, each with its reasons. Nobody reaches you without stating theirs.'];
        }

        if ($fresh === []) {
            return ['You are caught up.', 'Nothing new since you last looked. What you saw earlier is below for a day.'];
        }

        $total = count($fresh) + $this->waiting;
        $line = Moment::plural($total, 'introduction').' in this round';

        if ($this->waiting === 0) {
            $strong = count(array_filter($fresh, fn ($b) => count($b['reasons'] ?? []) >= 2));
            $line .= $strong > 0 ? ', '.$strong.' on two reasons or more.' : '.';
        } else {
            $line .= '.';
        }

        return [$line, 'Each row says why it fits.'];
    }

    public function render(Persona $persona, Arrival $arrival): View
    {
        $firstVisit = $this->beats === [] && ! $this->everShown;
        // A beat can leave the round (dismissed, switched off): the pane then moves to the next one that is still here.
        $this->selected = $this->validSelection($this->selected);

        [$headline, $lead] = $this->headline($firstVisit);

        return view('livewire.pulse.floor.round', [
            'headline' => $headline,
            'lead' => $lead,
            // An empty first visit to the Floor says what the matcher holds about the person, so the gap explains itself.
            'knows' => $firstVisit && $this->mode === 'all' ? $arrival->knows(auth()->user()) : [],
            'firstHref' => $this->arrival ? $arrival->action(auth()->user())['href'] : null,
            'emptyActions' => $persona->emptyActions(auth()->user(), $this->mode),
            'endAction' => $persona->endAction(auth()->user()),
            'readyAt' => $this->nextRoundAt ? Carbon::parse($this->nextRoundAt)->timezone(config('app.timezone')) : null,
        ]);
    }
}
