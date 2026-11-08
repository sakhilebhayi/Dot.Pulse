<?php

namespace App\Policies;

use App\Models\PulseMessage;
use App\Models\User;
use App\Services\Pulse\Lines\Access;

class PulseMessagePolicy
{
    public function view(User $user, PulseMessage $message): bool
    {
        return $user->can('view', $message->conversation);
    }

    /** Report someone else's message on a line you are on. */
    public function report(User $user, PulseMessage $message): bool
    {
        return $message->user_id !== $user->id
            && $message->kind !== 'system'
            && Access::isParticipant($user, $message->conversation);
    }
}
