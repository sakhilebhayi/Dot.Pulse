<?php

namespace App\Livewire\Pulse\Me;

use App\Models\PulseEntityInvitation;
use App\Services\Pulse\Entities\InvitationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/** "Me, Notifications": team invitations waiting for this person. Renders nothing when there are none. */
class Invitations extends Component
{
    public ?string $notice = null;

    /** @return Collection<int, PulseEntityInvitation> */
    #[Computed]
    public function pending()
    {
        return app(InvitationService::class)->pendingFor(auth()->user());
    }

    public function accept(int $id, InvitationService $invitations): void
    {
        try {
            $entity = $invitations->accept(auth()->user(), $id);
            $this->notice = 'You joined '.$entity->name.'.';
        } catch (AuthorizationException) {
            $this->notice = 'That invitation is no longer open.';
        }

        unset($this->pending);
    }

    public function decline(int $id, InvitationService $invitations): void
    {
        try {
            $invitations->decline(auth()->user(), $id);
            $this->notice = 'Declined. They are not told who you are beyond that.';
        } catch (AuthorizationException) {
            $this->notice = 'That invitation is no longer open.';
        }

        unset($this->pending);
    }

    public function render(): View
    {
        return view('livewire.pulse.me.invitations');
    }
}
