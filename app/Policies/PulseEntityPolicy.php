<?php

namespace App\Policies;

use App\Models\PulseEntity;
use App\Models\User;
use App\Services\Pulse\Lines\Access;

/**
 * Roles on a business page: owner (everything, including other owners), admin (page details, people below
 * admin, listings), editor (listings, answers), responder (answers only). Platform moderators can see and
 * moderate every page; they cannot edit one.
 */
class PulseEntityPolicy
{
    /** Anyone (even signed out) sees a live, approved page; members and platform moderators see their own pages in any state. */
    public function view(?User $user, PulseEntity $entity): bool
    {
        if ($entity->isLive()) {
            return true;
        }

        if ($user === null || $entity->trashed()) {
            return $user !== null && $user->isPlatformModerator();
        }

        return $entity->memberRole($user) !== null || $user->isPlatformModerator();
    }

    public function create(User $user): bool
    {
        return true; // the quota is enforced by EntityService
    }

    public function update(User $user, PulseEntity $entity): bool
    {
        return in_array($entity->memberRole($user), ['owner', 'admin'], true);
    }

    /** Offers, roles and images. */
    public function manageListings(User $user, PulseEntity $entity): bool
    {
        return in_array($entity->memberRole($user), ['owner', 'admin', 'editor'], true);
    }

    /**
     * People. An owner may touch anyone; an admin may touch editors and responders only. $targetRole is the role
     * being granted, changed or removed, when known.
     */
    public function manageMembers(User $user, PulseEntity $entity, ?string $targetRole = null): bool
    {
        $role = $entity->memberRole($user);

        if ($role === 'owner') {
            return true;
        }

        return $role === 'admin' && ($targetRole === null || in_array($targetRole, ['editor', 'responder'], true));
    }

    /**
     * Answer the page's lines (conversations, quotes): owner, admin and responder. Editors edit listings and do not read
     * lines. One list, Access::RESPONDER_ROLES, so the policy and the line services cannot disagree.
     */
    public function respond(User $user, PulseEntity $entity): bool
    {
        return in_array($entity->memberRole($user), Access::RESPONDER_ROLES, true);
    }

    public function archive(User $user, PulseEntity $entity): bool
    {
        return $entity->memberRole($user) === 'owner';
    }

    public function moderate(User $user, PulseEntity $entity): bool
    {
        return $user->isPlatformModerator();
    }
}
