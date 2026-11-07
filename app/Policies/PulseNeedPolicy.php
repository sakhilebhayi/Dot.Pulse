<?php

namespace App\Policies;

use App\Models\PulseNeed;
use App\Models\User;

class PulseNeedPolicy
{
    /**
     * A live open need is visible to any signed in person; a "matched" need only to its owner and moderators
     * (others reach it through the discovery index, which matches before it shows). Never to signed out visitors.
     */
    public function view(?User $user, PulseNeed $need): bool
    {
        if ($user === null) {
            return false;
        }

        if ($need->user_id === $user->id || $user->isPlatformModerator()) {
            return true;
        }

        return $need->isLive() && $need->visibility === 'open';
    }

    public function update(User $user, PulseNeed $need): bool
    {
        return $need->user_id === $user->id;
    }

    public function delete(User $user, PulseNeed $need): bool
    {
        return $need->user_id === $user->id;
    }

    public function moderate(User $user, PulseNeed $need): bool
    {
        return $user->isPlatformModerator();
    }
}
