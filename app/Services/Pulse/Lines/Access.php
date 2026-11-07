<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseEntityMember;
use App\Models\PulseProfile;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who is who on a line. A line has two sides. A person contacted directly is the recipient side; a business page
 * contacted is the recipient side and every responder of that page (owner, admin, responder) answers for it.
 * Editors manage listings but do not read lines. A page that starts a line (a talent invite) is the initiator side.
 */
class Access
{
    public const RESPONDER_ROLES = ['owner', 'admin', 'responder'];

    public static function canRespondFor(User $user, PulseEntity|int|null $entity): bool
    {
        if ($entity === null) {
            return false;
        }

        $id = $entity instanceof PulseEntity ? $entity->id : $entity;

        return PulseEntityMember::query()
            ->where('entity_id', $id)
            ->where('user_id', $user->id)
            ->whereIn('role', self::RESPONDER_ROLES)
            ->exists();
    }

    public static function isMemberOf(User $user, PulseEntity|int $entity): bool
    {
        $id = $entity instanceof PulseEntity ? $entity->id : $entity;

        return PulseEntityMember::query()->where('entity_id', $id)->where('user_id', $user->id)->exists();
    }

    /**
     * @param  list<int>|null  $pages  the pages the user answers for, when the caller already has them (a list of lines): saves one query per line
     * @return 'initiator'|'recipient'|null
     */
    public static function side(User $user, PulseConversation $c, ?array $pages = null): ?string
    {
        if ($c->initiator_id === $user->id) {
            return 'initiator';
        }

        if ($c->recipient_id === $user->id) {
            return 'recipient';
        }

        $answers = fn (?int $entityId) => $entityId !== null
            && ($pages !== null ? in_array($entityId, $pages, true) : self::canRespondFor($user, $entityId));

        if ($answers($c->initiator_entity_id)) {
            return 'initiator';
        }

        if ($answers($c->entity_id)) {
            return 'recipient';
        }

        return null;
    }

    public static function isParticipant(User $user, PulseConversation $c): bool
    {
        return self::side($user, $c) !== null;
    }

    public static function otherSide(string $side): string
    {
        return $side === 'initiator' ? 'recipient' : 'initiator';
    }

    /**
     * The users who answer for one side of a line.
     *
     * @return Collection<int, User>
     */
    public static function usersFor(PulseConversation $c, string $side): Collection
    {
        $ids = collect();
        $entityId = $side === 'initiator' ? $c->initiator_entity_id : $c->entity_id;
        $personId = $side === 'initiator' ? $c->initiator_id : $c->recipient_id;

        if ($entityId !== null) {
            $ids = PulseEntityMember::query()
                ->where('entity_id', $entityId)
                ->whereIn('role', self::RESPONDER_ROLES)
                ->pluck('user_id');
        }

        // A person contacted directly, or the member who started a page's invite, always counts.
        if ($personId !== null && ($entityId === null || $side === 'initiator')) {
            $ids->push($personId);
        }

        return $ids->isEmpty() ? collect() : User::query()->whereIn('id', $ids->unique())->get();
    }

    public static function isModerator(User $user): bool
    {
        return $user->isPlatformModerator();
    }

    /** True when the person has declared they are 18 or older. */
    public static function ageConfirmed(User|int $user): bool
    {
        $id = $user instanceof User ? $user->id : $user;

        return PulseProfile::query()->where('user_id', $id)->whereNotNull('age_confirmed_at')->exists();
    }
}
