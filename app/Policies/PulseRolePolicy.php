<?php

namespace App\Policies;

use App\Models\PulseRole;
use App\Models\User;

class PulseRolePolicy
{
    public function view(?User $user, PulseRole $role): bool
    {
        if ($role->isLive()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $this->manage($user, $role) || $user->isPlatformModerator();
    }

    public function manage(User $user, PulseRole $role): bool
    {
        return in_array($role->entity?->memberRole($user), ['owner', 'admin', 'editor'], true);
    }

    public function update(User $user, PulseRole $role): bool
    {
        return $this->manage($user, $role);
    }

    public function publish(User $user, PulseRole $role): bool
    {
        return $this->manage($user, $role);
    }

    public function delete(User $user, PulseRole $role): bool
    {
        return $this->manage($user, $role);
    }

    public function moderate(User $user, PulseRole $role): bool
    {
        return $user->isPlatformModerator();
    }
}
