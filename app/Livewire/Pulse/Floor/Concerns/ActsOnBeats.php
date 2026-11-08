<?php

namespace App\Livewire\Pulse\Floor\Concerns;

use App\Models\PulseMatch;
use App\Models\PulseSignal;
use App\Services\Pulse\Discovery\Floor;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Locked;

/**
 * What a person can do to a beat from any list of beats (the Floor, search, saved): save, unsave, say not for me,
 * switch a reason off. Every action goes through the discovery services; this trait only changes what is on screen
 * to match what the service did.
 *
 * `$beats` is the list on screen, as plain arrays from BeatPresenter. It is locked: the browser can read it, not edit it.
 */
trait ActsOnBeats
{
    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $beats = [];

    public function save(int $signalId, Floor $floor): void
    {
        $signal = PulseSignal::query()->find($signalId);

        if ($signal === null || ! $this->onScreen($signalId)) {
            return;
        }

        try {
            $floor->saveForLater(auth()->user(), $signal);
        } catch (AuthorizationException) {
            $this->say('That is not available to you any more.', 'bad');
            $this->forget($signalId);

            return;
        }

        $this->flag($signalId, true);
        $this->dispatch('pulse-saved-changed');
        $this->say('Saved. Find it under Saved.', 'good');
    }

    public function unsave(int $signalId, Floor $floor): void
    {
        $signal = PulseSignal::query()->find($signalId);

        if ($signal === null || ! $this->onScreen($signalId)) {
            return;
        }

        $floor->unsave(auth()->user(), $signal);
        $this->flag($signalId, false);
        $this->afterUnsave($signalId);
        $this->dispatch('pulse-saved-changed');
        $this->say('Removed from Saved.');
    }

    public function dismiss(int $signalId, ?string $reason = null, ?Floor $floor = null): void
    {
        $signal = PulseSignal::query()->find($signalId);

        if ($signal === null || ! $this->onScreen($signalId)) {
            return;
        }

        ($floor ?? app(Floor::class))->dismiss(auth()->user(), $signal, $reason);
        $this->forget($signalId);
        $this->say('Gone from your floor.');
    }

    /**
     * The person tapped a reason on the sync meter. The service switches that input off for them and re-judges the
     * match; the beat then shows the reasons that are left, or leaves the floor if none are.
     */
    public function removeReason(int $matchId, string $code, Floor $floor): void
    {
        $match = PulseMatch::query()->where('user_id', auth()->id())->find($matchId);

        if ($match === null) {
            return;
        }

        try {
            $floor->removeReason(auth()->user(), $match, $code);
        } catch (\InvalidArgumentException) {
            $this->say('That one is not something you set, so it stays.', 'bad');

            return;
        }

        // The input is off for every beat, so every beat on screen is re-judged and redrawn, not only the one tapped.
        $onScreen = array_values(array_filter(array_map(fn (array $b) => (int) ($b['match_id'] ?? 0), $this->beats)));
        $floor->rejudgeShown(auth()->user(), $onScreen);

        $fresh = PulseMatch::query()->where('user_id', auth()->id())->whereIn('id', $onScreen)->get()->keyBy('id');
        $this->beats = array_values(array_filter(array_map(function (array $b) use ($fresh) {
            $m = $fresh->get((int) ($b['match_id'] ?? 0));

            if ($m === null) {
                return $b;
            }

            return $m->dismissed_at !== null ? null : array_merge($b, ['reasons' => BeatReasons::fromMatch((array) $m->reasons), 'score' => (float) $m->score]);
        }, $this->beats)));

        $match->refresh();

        if ($match->dismissed_at !== null) {
            $this->say('Removed. That beat no longer fits, so it is gone.', 'good');

            return;
        }

        $this->say('Removed. We will stop using that.', 'good');
    }

    /** Hook: a list that shows only saved beats drops the beat when it is unsaved. */
    protected function afterUnsave(int $signalId): void {}

    protected function onScreen(int $signalId): bool
    {
        foreach ($this->beats as $b) {
            if ((int) ($b['signal_id'] ?? 0) === $signalId) {
                return true;
            }
        }

        return false;
    }

    protected function flag(int $signalId, bool $saved): void
    {
        $this->beats = array_map(
            fn (array $b) => (int) ($b['signal_id'] ?? 0) === $signalId ? array_merge($b, ['saved' => $saved]) : $b,
            $this->beats,
        );
    }

    protected function forget(int $signalId): void
    {
        $this->beats = array_values(array_filter($this->beats, fn (array $b) => (int) ($b['signal_id'] ?? 0) !== $signalId));
    }

    protected function forgetMatch(int $matchId): void
    {
        $this->beats = array_values(array_filter($this->beats, fn (array $b) => (int) ($b['match_id'] ?? 0) !== $matchId));
    }

    protected function say(string $message, ?string $kind = null): void
    {
        $this->dispatch('toast', message: $message, kind: $kind);
    }
}
