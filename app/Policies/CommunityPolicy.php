<?php

namespace App\Policies;

use App\Models\Community;
use App\Models\User;

/**
 * The only place the "who can do what in a community" rules are written.
 * Visibility itself is Community::isVisibleTo().
 */
class CommunityPolicy
{
    public function view(?User $user, Community $community): bool
    {
        return $community->isVisibleTo($user);
    }

    /** Posting into a community requires being a member of it. */
    public function post(User $user, Community $community): bool
    {
        return $community->isMember($user);
    }

    /** Approve or deny membership requests. */
    public function manageMembers(User $user, Community $community): bool
    {
        return $community->isManager($user);
    }

    /** Remove content and resolve reports inside this community. */
    public function moderate(User $user, Community $community): bool
    {
        return $community->isManager($user);
    }
}
