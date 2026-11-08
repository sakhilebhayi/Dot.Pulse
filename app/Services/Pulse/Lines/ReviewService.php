<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseReview;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Earned reviews (D22). A review exists only after a line whose outcome both sides confirmed as a result (won,
 * hired, partnered), is written by someone on that line about the other side, and is one per person per line.
 * Reviews are blind: neither side sees the other's, or anyone else, until both have submitted or 14 days have
 * passed. The public may show an author by name or as a pseudonymous "Customer". Text is checked by the
 * deterministic moderation rules; a held review is invisible until a moderator approves it.
 */
class ReviewService
{
    public function __construct(private readonly TextGuard $guard) {}

    /**
     * The line that lets this person review this subject, if any.
     *
     * @param  PulseEntity|User  $subject  the page or person being reviewed
     */
    public function canReview(User $u, PulseEntity|User $subject): ?PulseConversation
    {
        $candidates = PulseConversation::query()->successfulIncludingFlagged()
            ->when($subject instanceof PulseEntity,
                fn ($q) => $q->where(fn ($w) => $w->where('entity_id', $subject->id)->orWhere('initiator_entity_id', $subject->id)),
                fn ($q) => $q->where(fn ($w) => $w
                    ->where(fn ($a) => $a->where('initiator_id', $subject->id)->whereNull('initiator_entity_id'))
                    ->orWhere(fn ($a) => $a->where('recipient_id', $subject->id)->whereNull('entity_id'))))
            ->visibleTo($u)
            ->latest('id')
            ->limit(25)
            ->get();

        foreach ($candidates as $c) {
            if ($this->reviewTarget($u, $c) === null) {
                continue;
            }

            [$type, $id] = $this->reviewTarget($u, $c);
            if ($type !== $subject->getMorphClass() || (int) $id !== (int) $subject->getKey()) {
                continue;
            }

            if ($this->windowClosed($c) || PulseReview::query()->where('conversation_id', $c->id)->where('user_id', $u->id)->exists()) {
                continue;
            }

            return $c;
        }

        return null;
    }

    /**
     * @param  array{rating: int, body?: ?string, pseudonymous?: bool}  $r
     */
    public function submit(User $by, PulseConversation $c, array $r): PulseReview
    {
        $c->refresh();

        if (! Access::isParticipant($by, $c)) {
            throw new AuthorizationException('You were not part of this line.');
        }

        if (! OutcomeService::successful($c)) {
            throw LineException::because('not_allowed', 'You can review once both sides have confirmed a result.');
        }

        $target = $this->reviewTarget($by, $c);
        if ($target === null) {
            throw LineException::because('not_allowed', 'Reviews open once they reveal who they are.');
        }

        if ($this->windowClosed($c)) {
            throw LineException::because('not_allowed', 'The time to review this has passed.');
        }

        $rating = (int) ($r['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages(['rating' => 'Pick a rating from 1 to 5.']);
        }

        $body = $this->guard->block($r['body'] ?? '', (int) config('pulse.lines.review_max', 1000));
        if ($body !== '' && $this->guard->violations($body) !== []) {
            throw ValidationException::withMessages(['body' => 'Leave links and contact details out of a review.']);
        }

        $verdict = $body === '' ? ['verdict' => 'allow'] : $this->guard->assertNotBlocked($body, $by, 'body', ['surface' => 'review']);

        $side = Access::side($by, $c);

        // A professional who has not revealed themselves on an anonymous line is never named, whatever the form said:
        // a public review under their name would undo the anonymity the line was built on.
        $pseudonymous = (bool) ($r['pseudonymous'] ?? false) || ($c->identity_hidden && $side === 'recipient' && $c->entity_id === null);

        try {
            $review = DB::transaction(function () use ($by, $c, $target, $rating, $body, $pseudonymous, $verdict, $side) {
                $review = PulseReview::create([
                    'user_id' => $by->id,
                    'reviewable_type' => $target[0],
                    'reviewable_id' => $target[1],
                    'rating' => $rating,
                    'body' => $body === '' ? null : $body,
                    'is_verified' => true, // earned: stands on a confirmed outcome
                    'conversation_id' => $c->id,
                    'reviewer_side' => $side,
                    'outcome' => $c->outcome,
                    'pseudonymous' => $pseudonymous,
                    // A result the ring check flagged holds its reviews with it; the moderator decides both together.
                    'moderation' => $c->needs_review || $verdict['verdict'] === 'hold' ? 'held' : 'approved',
                    'held_for_ring' => (bool) $c->needs_review,
                ]);

                $other = PulseReview::query()->where('conversation_id', $c->id)->where('user_id', '!=', $by->id)->exists();
                if ($other) {
                    PulseReview::query()->where('conversation_id', $c->id)->whereNull('revealed_at')->update(['revealed_at' => now()]);
                }

                AuditLog::record($by, 'review.submit', $review, ['both' => $other]);

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            throw LineException::because('exists', 'You already reviewed this.');
        }

        $review->refresh();
        $this->notifyAfterSubmit($c, $by, $side, $review);

        return $review;
    }

    /** Reviews whose 14 days are up become public. Returns how many were stamped. */
    public function revealDue(): int
    {
        return PulseReview::query()->whereNull('revealed_at')
            ->where('created_at', '<=', now()->subDays(PulseReview::BLIND_DAYS))
            ->update(['revealed_at' => now()]);
    }

    /** A platform moderator approves or removes a review the rules held. */
    public function moderate(User $moderator, PulseReview $review, bool $approve): void
    {
        if (! $moderator->isPlatformModerator()) {
            throw new AuthorizationException('Only moderators can do that.');
        }

        $review->forceFill(['moderation' => $approve ? 'approved' : 'removed'])->save();
        AuditLog::record($moderator, $approve ? 'review.approve' : 'review.remove', $review);
    }

    /**
     * Public reviews of a page or person: revealed, approved, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forSubject(PulseEntity|User $subject, int $limit = 20): Collection
    {
        return PulseReview::query()->revealed()
            ->where('reviewable_type', $subject->getMorphClass())->where('reviewable_id', $subject->getKey())
            ->with('author:id,name')->latest('id')->limit($limit)->get()
            ->map(fn (PulseReview $r) => $this->present($r));
    }

    /** @return array{count: int, average: ?float} */
    public function summary(PulseEntity|User $subject): array
    {
        $row = PulseReview::query()->revealed()
            ->where('reviewable_type', $subject->getMorphClass())->where('reviewable_id', $subject->getKey())
            ->selectRaw('COUNT(*) as n, AVG(rating) as avg_rating')->first();

        $n = (int) ($row->n ?? 0);

        return ['count' => $n, 'average' => $n > 0 ? round((float) $row->avg_rating, 1) : null];
    }

    /** The reviews this person wrote, visible to them whether or not they are revealed yet. */
    public function mine(User $u): Collection
    {
        return PulseReview::query()->where('user_id', $u->id)->latest('id')->get();
    }

    /** @return array<string, mixed> */
    public function present(PulseReview $r): array
    {
        $label = $r->pseudonymous
            ? match ($r->outcome) {
                'hired' => 'A person who was hired',
                'partnered' => 'A partner',
                default => 'A customer',
            }
        : $this->shortName($r->author?->name);

        return [
            'id' => $r->id,
            'rating' => $r->rating,
            'body' => $r->body,
            'author' => $label,
            'pseudonymous' => $r->pseudonymous,
            'outcome' => $r->outcome,
            'verified' => true,
            'created_at' => $r->created_at,
        ];
    }

    /** @return array{0: string, 1: int}|null morph class and id of the subject this person would review */
    private function reviewTarget(User $by, PulseConversation $c): ?array
    {
        $side = Access::side($by, $c);
        if ($side === null) {
            return null;
        }

        $other = Access::otherSide($side);
        $entityId = $other === 'initiator' ? $c->initiator_entity_id : $c->entity_id;

        if ($entityId !== null) {
            return [(new PulseEntity)->getMorphClass(), $entityId];
        }

        if ($c->identity_hidden && $other === 'recipient') {
            return null;
        }

        $userId = $other === 'initiator' ? $c->initiator_id : $c->recipient_id;

        return $userId === null ? null : [(new User)->getMorphClass(), $userId];
    }

    private function windowClosed(PulseConversation $c): bool
    {
        $confirmedAt = collect([$c->outcome_initiator_at, $c->outcome_recipient_at])->filter()->max();

        return $confirmedAt === null || $confirmedAt->lt(now()->subDays((int) config('pulse.lines.review_window_days', 60)));
    }

    private function notifyAfterSubmit(PulseConversation $c, User $by, string $side, PulseReview $review): void
    {
        $lines = app(LineService::class);
        $other = Access::otherSide($side);
        $both = $review->revealed_at !== null;

        $lines->notifySide($c, $other, $by, [
            'type' => $both ? 'review_revealed' : 'review_waiting',
            'title' => $both ? 'Reviews for '.$c->context_title.' are now visible' : 'The other side reviewed '.$c->context_title,
            'body' => $both ? 'Both reviews are public now.' : 'Write yours to see theirs. Both stay hidden until you have both written one, or 14 days pass.',
        ], 'outcomes');

        if ($both) {
            $lines->notifySide($c, $side, $by, [
                'type' => 'review_revealed',
                'title' => 'Reviews for '.$c->context_title.' are now visible',
                'body' => 'Both reviews are public now.',
            ], 'outcomes');
        }
    }

    private function shortName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        if ($parts === [] || $parts[0] === '') {
            return 'A Pulse member';
        }

        return count($parts) > 1 ? $parts[0].' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.' : $parts[0];
    }
}
