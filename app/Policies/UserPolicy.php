<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /** Anyone signed in can report a profile that is not their own. */
    public function report(User $viewer, User $target): bool
    {
        return $viewer->id !== $target->id;
    }
}
