<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseConversation;
use App\Models\PulseDoor;
use App\Models\PulseEntity;
use App\Models\PulseEntityBlock;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseProfile;
use App\Models\PulseRole;
use App\Models\PulseSignal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides whether one person may start contact with another person or a business page, and how: directly, or as
 * a request the other side accepts (D8). Order: self, age gate, blocks (never revealed), the page being live, the
 * contact policy, then the knock budget. The `why` is plain words for the person asking and never says that
 * someone blocked them.
 *
 * Policies: anyone (a direct line), context (only when the request carries something of theirs that is live),
 * requests (always a request), nobody. A door of theirs that is open turns any policy except nobody into a request.
 * Someone they have already talked to is not a stranger: no budget is spent and the policy does not re-gate them.
 */
class ContactGate
{
    public function __construct(private readonly KnockBudget $budget) {}

    /**
     * @param  User|PulseEntity|PulseSignal|PulseDoor|PulseOffer|PulseRole|PulseNeed  $target
     * @return array{allowed: bool, mode: string, why: string, code: string, stranger: bool}
     */
    public function can(User $from, Model $target, ?Model $context = null): array
    {
        [$targetUser, $targetEntity, $context] = $this->resolve($target, $context);

        if ($targetUser === null && $targetEntity === null) {
            return $this->no('unavailable', 'They cannot be contacted right now.');
        }

        if ($targetUser?->is($from)) {
            return $this->no('self', 'That is you.');
        }

        if ($targetEntity !== null && Access::isMemberOf($from, $targetEntity)) {
            return $this->no('own_page', 'You belong to this page. Use its lines instead.');
        }

        if (! Access::ageConfirmed($from)) {
            return $this->no('age', 'Pulse is for people 18 and over. Confirm your age in your profile to start conversations.');
        }

        if ($targetUser !== null && ! Access::ageConfirmed($targetUser)) {
            return $this->no('unavailable', 'They cannot be contacted right now.');
        }

        if ($this->isBlocked($from, $targetUser, $targetEntity)) {
            return $this->no('unavailable', 'They cannot be contacted right now.');
        }

        if ($targetEntity !== null && $targetEntity->status !== 'live') {
            return $this->no('page_closed', 'This page is not taking messages right now.');
        }

        $policy = $this->policy($targetUser, $targetEntity);

        if ($policy === 'nobody') {
            return $this->no('nobody', 'They are not taking new messages.');
        }

        $stranger = ! $this->hasRelationship($from, $targetUser, $targetEntity);

        if (! $stranger) {
            return $this->yes('direct', 'You have talked before.', false, 'known');
        }

        $contextOk = $context !== null
            && Context::isLive($context)
            && (Context::belongsToTarget($context, $targetUser, $targetEntity) || $this->isQuotePath($from, $context, $targetEntity));
        $viaDoor = $contextOk && $context instanceof PulseDoor;

        $mode = match (true) {
            $viaDoor => $policy === 'anyone' ? 'direct' : 'request',
            $policy === 'anyone' => 'direct',
            $policy === 'context' => $contextOk ? 'request' : null,
            default => 'request', // requests
        };

        if ($mode === null) {
            return $this->no('needs_context', 'They only take messages that start from something of theirs. Open one of their listings and ask from there.');
        }

        if ($this->budget->remaining($from) < 1) {
            return $this->no('budget', 'You have used this week\'s first contacts. They refill on '.$this->budget->resetsAt($from)->format('j M').' or when someone replies to you.');
        }

        return $this->yes($mode, $mode === 'direct' ? 'You can message them directly.' : 'Your message goes to them as a request. They choose whether to reply.', true, 'ok');
    }

    /** The person's own contact policy: profile (default 'context') or the page's (default 'anyone'). */
    public function policy(?User $user, ?PulseEntity $entity): string
    {
        if ($entity !== null) {
            return $entity->contact_policy ?: 'anyone';
        }

        $profile = PulseProfile::query()->where('user_id', $user?->id)->first();

        return $profile?->contact_policy ?: 'context';
    }

    /** Whether the two have a line that was answered and is still open or ended normally: not strangers. */
    public function hasRelationship(User $from, ?User $targetUser, ?PulseEntity $targetEntity): bool
    {
        $q = PulseConversation::query()->where(function ($w) {
            $w->where('status', PulseConversation::OPEN)
                ->orWhere(fn ($c) => $c->where('status', PulseConversation::CLOSED)
                    ->whereNotNull('first_response_at')
                    ->whereIn('closed_reason', ['closed', 'outcome']));
        });

        if ($targetEntity !== null) {
            $q->where(fn ($w) => $w->where(fn ($a) => $a->where('initiator_id', $from->id)->where('entity_id', $targetEntity->id))
                ->orWhere(fn ($a) => $a->where('recipient_id', $from->id)->where('initiator_entity_id', $targetEntity->id)));
        } else {
            $q->whereNull('entity_id')->where(fn ($w) => $w
                ->where(fn ($a) => $a->where('initiator_id', $from->id)->where('recipient_id', $targetUser->id))
                ->orWhere(fn ($a) => $a->where('initiator_id', $targetUser->id)->where('recipient_id', $from->id)));
        }

        return $q->exists();
    }

    /**
     * @return array{0: ?User, 1: ?PulseEntity, 2: ?Model}
     */
    private function resolve(Model $target, ?Model $context): array
    {
        $user = null;
        $entity = null;

        switch (true) {
            case $target instanceof User:
                $user = $target;
                break;
            case $target instanceof PulseEntity:
                $entity = $target;
                break;
            case $target instanceof PulseOffer:
            case $target instanceof PulseRole:
                $entity = $target->entity;
                $context ??= $target;
                break;
            case $target instanceof PulseNeed:
                $user = $target->user;
                $context ??= $target;
                break;
            case $target instanceof PulseDoor:
                $user = $target->owner_id ? $target->owner : null;
                $entity = $target->entity_id ? $target->entity : null;
                $context ??= $target;
                break;
            case $target instanceof PulseSignal:
                $user = $target->owner_id ? $target->owner : null;
                $entity = $target->owner_id ? null : $target->entity;
                $context ??= $target;
                break;
        }

        return [$user, $entity, $context];
    }

    private function isBlocked(User $from, ?User $targetUser, ?PulseEntity $targetEntity): bool
    {
        if ($targetUser !== null && BlockService::blocked($from, $targetUser)) {
            return true;
        }

        return $targetEntity !== null
            && PulseEntityBlock::query()->where('entity_id', $targetEntity->id)->where('user_id', $from->id)->exists();
    }

    /** "I need X" sent to a seller that offers quotes: the sender's own live need is a valid context. */
    private function isQuotePath(User $from, Model $context, ?PulseEntity $targetEntity): bool
    {
        return $context instanceof PulseNeed
            && $context->user_id === $from->id
            && $targetEntity !== null
            && $targetEntity->is_seller
            && PulseOffer::query()->where('entity_id', $targetEntity->id)->where('status', 'live')
                ->where('moderation', 'approved')->where('accepts_quotes', true)->exists();
    }

    private function yes(string $mode, string $why, bool $stranger, string $code): array
    {
        return ['allowed' => true, 'mode' => $mode, 'why' => $why, 'code' => $code, 'stranger' => $stranger];
    }

    private function no(string $code, string $why): array
    {
        return ['allowed' => false, 'mode' => 'blocked', 'why' => $why, 'code' => $code, 'stranger' => true];
    }
}
