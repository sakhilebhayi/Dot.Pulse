<?php

namespace App\Policies;

use App\Models\PulseConversation;
use App\Models\PulseMessage;
use App\Models\PulseReport;
use App\Models\User;
use App\Services\Pulse\Lines\Access;

/**
 * Who may do what on a line. A participant is either side: the people involved, and every responder of a page
 * that is involved. A stranger and a page editor have no access. Platform moderators can read a line only while
 * it has an open report about it or about one of its messages.
 */
class PulseConversationPolicy
{
    public function view(User $user, PulseConversation $c): bool
    {
        return Access::isParticipant($user, $c) || ($user->isPlatformModerator() && $this->hasOpenReport($c));
    }

    public function send(User $user, PulseConversation $c): bool
    {
        return $c->status === PulseConversation::OPEN && Access::isParticipant($user, $c);
    }

    public function accept(User $user, PulseConversation $c): bool
    {
        return $c->status === PulseConversation::REQUESTED && Access::side($user, $c) === 'recipient';
    }

    public function decline(User $user, PulseConversation $c): bool
    {
        return $this->accept($user, $c);
    }

    public function ignore(User $user, PulseConversation $c): bool
    {
        return $this->accept($user, $c);
    }

    /** Either side can close an open line; only the sender can withdraw a request (the recipient declines instead). */
    public function close(User $user, PulseConversation $c): bool
    {
        $side = Access::side($user, $c);

        return match ($c->status) {
            PulseConversation::OPEN => $side !== null,
            PulseConversation::REQUESTED => $side === 'initiator',
            default => false,
        };
    }

    /** A page's responders send the quote slip on a quote request line. */
    public function respond(User $user, PulseConversation $c): bool
    {
        return $c->kind === 'quote'
            && in_array($c->status, [PulseConversation::REQUESTED, PulseConversation::OPEN], true)
            && $c->entity_id !== null
            && Access::canRespondFor($user, $c->entity_id);
    }

    /** Only the invited professional can show who they are. */
    public function reveal(User $user, PulseConversation $c): bool
    {
        return $c->identity_hidden && $c->recipient_id === $user->id && $c->status === PulseConversation::OPEN;
    }

    public function report(User $user, PulseConversation $c): bool
    {
        return Access::isParticipant($user, $c);
    }

    /** Outcomes are set by the people on the line, once it was answered. */
    public function outcome(User $user, PulseConversation $c): bool
    {
        return Access::isParticipant($user, $c)
            && in_array($c->status, [PulseConversation::OPEN, PulseConversation::CLOSED], true)
            && $c->first_response_at !== null;
    }

    private function hasOpenReport(PulseConversation $c): bool
    {
        return PulseReport::query()->open()->where(function ($q) use ($c) {
            $q->where(fn ($w) => $w->where('reportable_type', PulseConversation::class)->where('reportable_id', $c->id))
                ->orWhere(fn ($w) => $w->where('reportable_type', PulseMessage::class)
                    ->whereIn('reportable_id', PulseMessage::query()->where('pulse_conversation_id', $c->id)->select('id')));
        })->exists();
    }
}
