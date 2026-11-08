<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Outcomes (D9): what a line came to. One side proposes an outcome, the other confirms it; only an outcome both
 * sides confirmed counts. confirmed() and the Conversation::confirmed()/successful() scopes are what metrics,
 * reviews and verification use. A confirmed 'lost' closes the loop honestly but is not a result.
 */
class OutcomeService
{
    /** What each kind of line can come to. */
    public const ALLOWED = [
        'dm' => ['won', 'lost'],
        'enquiry' => ['won', 'lost'],
        'quote' => ['won', 'lost'],
        'application' => ['hired', 'lost'],
        'invite' => ['hired', 'lost'],
        'intro' => ['partnered', 'won', 'lost'],
        'collab' => ['partnered', 'lost'],
    ];

    public function __construct(private readonly LineService $lines) {}

    public function propose(User $by, PulseConversation $c, string $outcome): void
    {
        $c->refresh();
        Gate::forUser($by)->authorize('outcome', $c);

        if (! in_array($outcome, self::ALLOWED[$c->kind] ?? [], true)) {
            throw LineException::because('invalid', 'That is not something this kind of line can come to.');
        }

        if ($c->hasConfirmedOutcome()) {
            throw LineException::because('exists', 'Both sides already confirmed the outcome.');
        }

        $side = Access::side($by, $c);
        $other = Access::otherSide($side);

        $c->forceFill([
            'outcome' => $outcome,
            'outcome_side' => $side,
            'outcome_'.$side.'_at' => now(),
            'outcome_'.$other.'_at' => null,
            'outcome_disputed_at' => null,
        ])->save();

        $this->lines->writeMessage($c, $by, $side, 'The other side marked this as '.$this->word($outcome).'. Waiting for you to confirm.', 'system', ['event' => 'outcome_proposed', 'outcome' => $outcome], touch: false);
        AuditLog::record($by, 'line.outcome_propose', $c, ['outcome' => $outcome]);

        $this->lines->notifySide($c, $other, $by, [
            'type' => 'outcome_proposed',
            'title' => $this->lines->partyName($c, $side).' marked this as '.$this->word($outcome),
            'body' => 'Confirm it if it is right, or dispute it. About: '.$c->context_title,
        ], 'outcomes');
    }

    public function confirm(User $by, PulseConversation $c): void
    {
        $c->refresh();
        Gate::forUser($by)->authorize('outcome', $c);

        $side = Access::side($by, $c);

        if ($c->outcome === 'none' || $c->outcome_side === null) {
            throw LineException::because('not_open', 'Nobody has proposed an outcome yet.');
        }

        if ($c->outcome_side === $side) {
            throw LineException::because('not_allowed', 'The other side has to confirm your outcome.');
        }

        if ($c->hasConfirmedOutcome()) {
            return;
        }

        $moved = PulseConversation::query()->whereKey($c->id)
            ->where('outcome', $c->outcome)->whereNull('outcome_'.$side.'_at')
            ->update(['outcome_'.$side.'_at' => now(), 'updated_at' => now()]);

        if ($moved === 0) {
            throw LineException::because('not_open', 'That outcome changed. Look again.');
        }

        $c->refresh();
        $this->lines->writeMessage($c, $by, $side, 'Both sides confirmed: '.$this->word($c->outcome).'.', 'system', ['event' => 'outcome_confirmed', 'outcome' => $c->outcome], touch: false);
        AuditLog::record($by, 'line.outcome_confirm', $c, ['outcome' => $c->outcome]);

        // Accepted either way; a pair that keeps confirming each other's results is held back from public counts for a person to look at.
        app(RingCheck::class)->assess($c);

        $positive = in_array($c->outcome, PulseConversation::POSITIVE_OUTCOMES, true);
        foreach (['initiator', 'recipient'] as $s) {
            $this->lines->notifySide($c, $s, null, [
                'type' => 'outcome_confirmed',
                'title' => 'Confirmed: '.$this->word($c->outcome),
                'body' => $positive ? 'You can now leave a review. Reviews stay hidden until both sides have written one, or 14 days pass.' : 'Thank you for closing the loop.',
            ], 'outcomes');
        }
    }

    /** The other side disagrees with the proposed outcome: it is cleared and nobody's result counts. */
    public function dispute(User $by, PulseConversation $c): void
    {
        $c->refresh();
        Gate::forUser($by)->authorize('outcome', $c);

        $side = Access::side($by, $c);

        if ($c->outcome === 'none' || $c->outcome_side === $side || $c->hasConfirmedOutcome()) {
            throw LineException::because('not_open', 'There is no proposed outcome for you to dispute.');
        }

        $proposerSide = $c->outcome_side;

        $c->forceFill([
            'outcome' => 'none',
            'outcome_side' => null,
            'outcome_initiator_at' => null,
            'outcome_recipient_at' => null,
            'outcome_disputed_at' => now(),
        ])->save();

        $this->lines->writeMessage($c, $by, $side, 'The proposed outcome was disputed and cleared.', 'system', ['event' => 'outcome_disputed'], touch: false);
        AuditLog::record($by, 'line.outcome_dispute', $c);

        $this->lines->notifySide($c, $proposerSide, $by, [
            'type' => 'outcome_disputed',
            'title' => $this->lines->partyName($c, $side).' disputed the outcome',
            'body' => 'Talk it through here, then propose again. About: '.$c->context_title,
        ], 'outcomes');
    }

    /** Both sides agreed on the same, real outcome. */
    public static function confirmed(PulseConversation $c): bool
    {
        return $c->hasConfirmedOutcome();
    }

    /** Confirmed, and a result: won, hired or partnered. */
    public static function successful(PulseConversation $c): bool
    {
        return $c->hasConfirmedOutcome() && in_array($c->outcome, PulseConversation::POSITIVE_OUTCOMES, true);
    }

    /** Confirmed results that a page or a person can claim. */
    public static function confirmedCount(PulseEntity|User $subject): int
    {
        $q = PulseConversation::query()->successful();

        if ($subject instanceof PulseEntity) {
            return $q->where(fn ($w) => $w->where('entity_id', $subject->id)->orWhere('initiator_entity_id', $subject->id))->count();
        }

        return $q->where(fn ($w) => $w->where('initiator_id', $subject->id)->orWhere('recipient_id', $subject->id))
            ->whereNull('entity_id')->whereNull('initiator_entity_id')->count();
    }

    private function word(string $outcome): string
    {
        return match ($outcome) {
            'won' => 'won (the deal happened)',
            'lost' => 'lost (no deal)',
            'hired' => 'hired',
            'partnered' => 'a partnership',
            default => $outcome,
        };
    }
}
