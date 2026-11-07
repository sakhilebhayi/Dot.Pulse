<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseBlock;
use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseEntityBlock;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Blocking hides both ways and closes whatever is open. The blocked side is never told. A person can block another
 * person or a business page; a page (through a responder) can block a person from contacting it.
 */
class BlockService
{
    public function block(User $by, User|PulseEntity $target): void
    {
        if ($target instanceof User) {
            if ($target->is($by)) {
                throw LineException::because('invalid', 'You cannot block yourself.');
            }

            try {
                PulseBlock::query()->firstOrCreate(['blocker_id' => $by->id, 'blocked_id' => $target->id]);
            } catch (UniqueConstraintViolationException) {
                // blocked already: same end state
            }

            $this->severFollows($by, $target);
            $this->closeLinesBetweenUsers($by, $target);
            AuditLog::record($by, 'block.user', $target);

            return;
        }

        if (Access::isMemberOf($by, $target)) {
            throw LineException::because('invalid', 'You cannot block a page you belong to.');
        }

        try {
            PulseEntityBlock::query()->firstOrCreate(['entity_id' => $target->id, 'user_id' => $by->id, 'side' => PulseEntityBlock::BY_USER], ['created_by' => $by->id]);
        } catch (UniqueConstraintViolationException) {
        }

        DB::table('pulse_entity_followers')->where('entity_id', $target->id)->where('user_id', $by->id)->delete();
        $this->closeLinesWithEntity($target, $by);
        AuditLog::record($by, 'block.entity', $target);
    }

    /** A responder blocks a person from contacting their page. Existing lines with that person close. */
    public function blockAsEntity(User $by, PulseEntity $entity, User $person): void
    {
        if (! Access::canRespondFor($by, $entity)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('You do not answer for this page.');
        }

        try {
            PulseEntityBlock::query()->firstOrCreate(['entity_id' => $entity->id, 'user_id' => $person->id, 'side' => PulseEntityBlock::BY_ENTITY], ['created_by' => $by->id]);
        } catch (UniqueConstraintViolationException) {
        }

        $this->closeLinesWithEntity($entity, $person);
        AuditLog::record($by, 'block.entity_blocks_user', $entity);
    }

    public function unblock(User $by, User|PulseEntity $target): void
    {
        if ($target instanceof User) {
            PulseBlock::query()->where('blocker_id', $by->id)->where('blocked_id', $target->id)->delete();
            AuditLog::record($by, 'unblock.user', $target);

            return;
        }

        PulseEntityBlock::query()->where('entity_id', $target->id)->where('user_id', $by->id)->where('side', PulseEntityBlock::BY_USER)->delete();
        AuditLog::record($by, 'unblock.entity', $target);
    }

    /** Either person has blocked the other. */
    public static function blocked(User $a, User $b): bool
    {
        return PulseBlock::query()
            ->where(fn ($q) => $q->where('blocker_id', $a->id)->where('blocked_id', $b->id))
            ->orWhere(fn ($q) => $q->where('blocker_id', $b->id)->where('blocked_id', $a->id))
            ->exists();
    }

    /** A person and a page cannot see each other: either one blocked the other. */
    public static function entityHidden(User $user, PulseEntity $entity): bool
    {
        return PulseEntityBlock::query()->where('entity_id', $entity->id)->where('user_id', $user->id)->exists();
    }

    /**
     * Every user id that must not appear to this person, and to whom this person must not appear: both directions.
     * Feed this into the Floor, search and suggestions.
     *
     * @return list<int>
     */
    public static function hiddenUserIds(User $u): array
    {
        $blocked = PulseBlock::query()->where('blocker_id', $u->id)->pluck('blocked_id');
        $blockers = PulseBlock::query()->where('blocked_id', $u->id)->pluck('blocker_id');

        return $blocked->merge($blockers)->unique()->values()->all();
    }

    /**
     * Page ids hidden from this person (they blocked the page, or the page blocked them).
     *
     * @return list<int>
     */
    public static function hiddenEntityIds(User $u): array
    {
        return PulseEntityBlock::query()->where('user_id', $u->id)->pluck('entity_id')->unique()->values()->all();
    }

    private function severFollows(User $a, User $b): void
    {
        DB::table('pulse_followers')
            ->where(fn ($q) => $q->where('follower_id', $a->id)->where('following_id', $b->id))
            ->orWhere(fn ($q) => $q->where('follower_id', $b->id)->where('following_id', $a->id))
            ->delete();
    }

    private function closeLinesBetweenUsers(User $a, User $b): void
    {
        $ids = PulseConversation::query()
            ->whereIn('status', [PulseConversation::REQUESTED, PulseConversation::OPEN])
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('initiator_id', $a->id)->where('recipient_id', $b->id))
                ->orWhere(fn ($w) => $w->where('initiator_id', $b->id)->where('recipient_id', $a->id)))
            ->pluck('id');

        // Lines with a page where one of them is a responder and the other the customer.
        $viaPage = PulseConversation::query()
            ->whereIn('status', [PulseConversation::REQUESTED, PulseConversation::OPEN])
            ->whereNotNull('entity_id')
            ->whereIn('initiator_id', [$a->id, $b->id])
            ->get()
            ->filter(function (PulseConversation $c) use ($a, $b) {
                $other = $c->initiator_id === $a->id ? $b : $a;

                return Access::canRespondFor($other, $c->entity_id);
            })->pluck('id');

        $this->close($ids->merge($viaPage)->unique());
    }

    private function closeLinesWithEntity(PulseEntity $entity, User $person): void
    {
        $ids = PulseConversation::query()
            ->whereIn('status', [PulseConversation::REQUESTED, PulseConversation::OPEN])
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('entity_id', $entity->id)->where('initiator_id', $person->id))
                ->orWhere(fn ($w) => $w->where('initiator_entity_id', $entity->id)->where('recipient_id', $person->id)))
            ->pluck('id');

        $this->close($ids);
    }

    private function close(iterable $ids): void
    {
        $ids = collect($ids);

        if ($ids->isEmpty()) {
            return;
        }

        PulseConversation::query()->whereIn('id', $ids)->update([
            'status' => PulseConversation::CLOSED,
            'closed_reason' => 'blocked',
            'closed_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
