<?php

namespace App\Policies;

use App\Models\PulseConversation;
use App\Models\PulseQuote;
use App\Models\User;
use App\Services\Pulse\Lines\Access;

class PulseQuotePolicy
{
    public function view(User $user, PulseQuote $quote): bool
    {
        return Access::isParticipant($user, $quote->conversation);
    }

    /** The provider page's responders send the slip. */
    public function respond(User $user, PulseQuote|PulseConversation $subject): bool
    {
        $conversation = $subject instanceof PulseQuote ? $subject->conversation : $subject;

        return $conversation->kind === 'quote'
            && $conversation->entity_id !== null
            && Access::canRespondFor($user, $conversation->entity_id);
    }

    /** The person who asked accepts or declines. */
    public function decide(User $user, PulseQuote $quote): bool
    {
        return Access::side($user, $quote->conversation) === 'initiator';
    }

    public function accept(User $user, PulseQuote $quote): bool
    {
        return $this->decide($user, $quote);
    }

    public function decline(User $user, PulseQuote $quote): bool
    {
        return $this->decide($user, $quote);
    }
}
