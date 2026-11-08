<?php

namespace App\Policies;

use App\Models\PulseOffer;
use App\Models\User;

class PulseOfferPolicy
{
    public function view(?User $user, PulseOffer $offer): bool
    {
        if ($offer->isLive()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $this->manage($user, $offer) || $user->isPlatformModerator();
    }

    /** Owner, admin and editor of the page that holds it. */
    public function manage(User $user, PulseOffer $offer): bool
    {
        return in_array($offer->entity?->memberRole($user), ['owner', 'admin', 'editor'], true);
    }

    public function update(User $user, PulseOffer $offer): bool
    {
        return $this->manage($user, $offer);
    }

    public function publish(User $user, PulseOffer $offer): bool
    {
        return $this->manage($user, $offer);
    }

    public function delete(User $user, PulseOffer $offer): bool
    {
        return $this->manage($user, $offer);
    }

    public function moderate(User $user, PulseOffer $offer): bool
    {
        return $user->isPlatformModerator();
    }
}
