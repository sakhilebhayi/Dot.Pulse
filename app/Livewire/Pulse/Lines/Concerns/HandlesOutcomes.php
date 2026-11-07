<?php

namespace App\Livewire\Pulse\Lines\Concerns;

use App\Models\PulseConversation;
use App\Models\PulseReview;
use App\Services\Pulse\Lines\Access;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\OutcomeService;
use App\Services\Pulse\Lines\ReviewService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Outcomes and reviews on a line. One side proposes, the other confirms; nothing counts until both have. After a
 * confirmed result either side writes a blind review: the two show together, or after 14 days.
 */
trait HandlesOutcomes
{
    /** @var array{rating: int, body: string, pseudonymous: bool} */
    public array $review = ['rating' => 0, 'body' => '', 'pseudonymous' => false];

    public function proposeOutcome(string $outcome, OutcomeService $outcomes): void
    {
        $this->attempt(function ($user) use ($outcome, $outcomes) {
            $outcomes->propose($user, $this->line(), $outcome);
            $this->dispatch('toast', message: 'Marked. It counts once they confirm.', kind: 'good');
        });
    }

    public function confirmOutcome(OutcomeService $outcomes): void
    {
        $this->attempt(function ($user) use ($outcomes) {
            $outcomes->confirm($user, $this->line());
            $this->dispatch('toast', message: 'Confirmed. Both sides agree.', kind: 'good');
        });
    }

    public function disputeOutcome(OutcomeService $outcomes): void
    {
        $this->attempt(function ($user) use ($outcomes) {
            $outcomes->dispute($user, $this->line());
            $this->dispatch('toast', message: 'Disputed. The outcome is cleared. Talk it through, then mark it again.', kind: 'good');
        });
    }

    public function submitReview(ReviewService $reviews): void
    {
        $this->failure = null;
        $c = $this->line();

        $this->validate([
            'review.rating' => ['required', 'integer', 'between:1,5'],
            'review.body' => ['nullable', 'string', 'max:'.(int) config('pulse.lines.review_max', 1000)],
        ], [
            'review.rating.required' => 'Pick a rating from 1 to 5.',
            'review.rating.between' => 'Pick a rating from 1 to 5.',
            'review.rating.integer' => 'Pick a rating from 1 to 5.',
            'review.body.max' => 'Keep it short. Under '.(int) config('pulse.lines.review_max', 1000).' characters.',
        ]);

        // On an anonymous line the public label must not carry the professional's name.
        $forcePseudonym = $c->identity_hidden && Access::side(auth()->user(), $c) === 'recipient';

        try {
            $reviews->submit(auth()->user(), $c, [
                'rating' => $this->review['rating'],
                'body' => $this->review['body'],
                'pseudonymous' => $forcePseudonym || (bool) $this->review['pseudonymous'],
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('review.'.$field, $messages[0]);
            }

            return;
        } catch (LineException $e) {
            $this->failure = $e->getMessage();

            return;
        }

        $this->review = ['rating' => 0, 'body' => '', 'pseudonymous' => false];
        $this->dispatch('toast', message: 'Review written. It stays hidden until both of you have written one.', kind: 'good');
    }

    /**
     * What the outcome bar shows, from the presenter and the line's own columns.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    protected function outcomeState(PulseConversation $c, array $line): array
    {
        $user = auth()->user();
        $allowed = in_array($c->kind, array_keys(OutcomeService::ALLOWED), true) ? OutcomeService::ALLOWED[$c->kind] : [];

        return [
            'visible' => Gate::forUser($user)->allows('outcome', $c),
            'choices' => $allowed,
            'value' => $line['outcome']['value'],
            'confirmed' => $line['outcome']['confirmed'],
            'mine' => $line['outcome']['proposed_by_me'],
            'awaiting_me' => $line['outcome']['awaiting_me'],
            'disputed' => $c->outcome === 'none' && $c->outcome_disputed_at !== null,
            'positive' => $line['outcome']['confirmed'] && in_array($line['outcome']['value'], PulseConversation::POSITIVE_OUTCOMES, true),
        ];
    }

    /**
     * Reviews on this line as the viewer may see them: their own always, the other side's once both are written or 14 days pass.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    protected function reviewState(PulseConversation $c, array $line): array
    {
        $user = auth()->user();
        $positive = $c->hasConfirmedOutcome() && in_array($c->outcome, PulseConversation::POSITIVE_OUTCOMES, true);

        if (! $positive || Access::side($user, $c) === null) {
            return ['show' => false];
        }

        $all = PulseReview::query()->where('conversation_id', $c->id)->orderBy('id')->get();
        $mine = $all->firstWhere('user_id', $user->id);
        $theirs = $all->first(fn (PulseReview $r) => $r->user_id !== $user->id
            && $r->moderation === 'approved'
            && ($r->revealed_at !== null || $r->created_at->lte(now()->subDays(PulseReview::BLIND_DAYS))));

        $confirmedAt = collect([$c->outcome_initiator_at, $c->outcome_recipient_at])->filter()->max();
        $open = $confirmedAt !== null && $confirmedAt->gte(now()->subDays((int) config('pulse.lines.review_window_days', 60)));
        $reviewable = ! ($c->identity_hidden && Access::side($user, $c) === 'initiator');

        return [
            'show' => true,
            'can_write' => $mine === null && $open && $reviewable,
            'closed' => $mine === null && ! $open,
            'unavailable' => $mine === null && ! $reviewable,
            'mine' => $mine,
            'theirs' => $theirs,
            'reveals_on' => $mine?->created_at?->copy()->addDays(PulseReview::BLIND_DAYS),
            'revealed' => $mine !== null && $theirs !== null,
            'force_pseudonym' => $c->identity_hidden && Access::side($user, $c) === 'recipient',
            'window_ends' => $confirmedAt?->copy()->addDays((int) config('pulse.lines.review_window_days', 60)),
        ];
    }
}
