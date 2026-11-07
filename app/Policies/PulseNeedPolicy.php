<?php

namespace App\Policies;

use App\Models\PulseMatch;
use App\Models\PulseNeed;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Lines\BlockService;

class PulseNeedPolicy
{
    /**
     * The one place that decides who may open a need. The owner and moderators always can. A live open need is visible
     * to any signed in person. A live "matched" need is visible to the people Pulse matched it to who have not
     * dismissed it. Never to signed out visitors. Pages and components ask this; none keeps its own copy of the rule.
     */
    public function view(?User $user, PulseNeed $need): bool
    {
        if ($user === null) {
            return false;
        }

        if ($need->user_id === $user->id || $user->isPlatformModerator()) {
            return true;
        }

        if (! $need->isLive()) {
            return false;
        }

        // Hidden both ways: a person blocked in either direction cannot open this need.
        if ($need->user !== null && BlockService::blocked($user, $need->user)) {
            return false;
        }

        return $need->visibility === 'open' || $this->matchedTo($user, $need);
    }

    private function matchedTo(User $user, PulseNeed $need): bool
    {
        $signalId = PulseSignal::query()->where('subject_type', 'need')->where('subject_id', $need->id)->where('status', 'live')->value('id');

        return $signalId !== null
            && PulseMatch::query()->where('signal_id', $signalId)->where('user_id', $user->id)->whereNull('dismissed_at')->exists();
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
