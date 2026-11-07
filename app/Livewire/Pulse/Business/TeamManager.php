<?php

namespace App\Livewire\Pulse\Business;

use App\Livewire\Pulse\Business\Support\HandlesServiceErrors;
use App\Models\PulseEntity;
use App\Models\PulseEntityMember;
use App\Models\User;
use App\Services\Pulse\Entities\EntityService;
use App\Services\Pulse\Entities\InvitationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The Team tab: who can do what on this page. People join by invitation: the owner or an admin invites an email address and the person says yes on their own hub.
 * The service enforces the rules: an admin can only manage editors and responders, and the last owner is never removed.
 */
class TeamManager extends Component
{
    use HandlesServiceErrors;

    #[Locked]

    public int $entityId;

    public string $email = '';

    public string $role = 'editor';

    public ?string $notice = null;

    public function mount(PulseEntity $entity): void
    {
        Gate::authorize('manageMembers', $entity);
        $this->entityId = $entity->id;
    }

    #[Computed]
    public function entity(): PulseEntity
    {
        return PulseEntity::query()->findOrFail($this->entityId);
    }

    /** @return Collection<int, PulseEntityMember> */
    #[Computed]
    public function members()
    {
        return $this->entity->members()->with('user:id,name,email')->orderByRaw("case role when 'owner' then 0 when 'admin' then 1 when 'editor' then 2 else 3 end")->orderBy('id')->get();
    }

    #[Computed]
    public function myRole(): ?string
    {
        return $this->entity->memberRole(auth()->user());
    }

    /** @return array<string, string> the roles this person may hand out */
    #[Computed]
    public function grantable(): array
    {
        $all = ['owner' => 'Owner', 'admin' => 'Admin', 'editor' => 'Editor', 'responder' => 'Responder'];

        return $this->myRole === 'owner' ? $all : array_intersect_key($all, array_flip(['editor', 'responder']));
    }

    /** Invite by email. The answer is the same whether or not the address belongs to anyone on Pulse. */
    public function add(InvitationService $invitations): void
    {
        $this->resetErrorBag();
        $this->notice = null;
        Gate::authorize('manageMembers', $this->entity);

        $this->validate(['email' => ['required', 'email', 'max:255'], 'role' => ['required', 'in:owner,admin,editor,responder']], [], ['email' => 'email']);

        try {
            $this->notice = $invitations->invite(auth()->user(), $this->entity, $this->email, $this->role);
        } catch (AuthorizationException) {
            $this->addError('role', 'You cannot give that role. Owners can give any role; admins can give editor or responder.');

            return;
        } catch (ValidationException $e) {
            $this->fail($e, ['member' => 'email']);

            return;
        }

        unset($this->invitations);
        $this->email = '';
    }

    public function revoke(int $invitationId, InvitationService $invitations): void
    {
        $this->resetErrorBag();

        try {
            $invitations->revoke(auth()->user(), $this->entity, $invitationId);
        } catch (AuthorizationException|ModelNotFoundException) {
            $this->notice = 'You cannot take that invitation back.';

            return;
        }

        unset($this->invitations);
        $this->notice = 'Invitation taken back.';
    }

    /** @return Collection<int, \App\Models\PulseEntityInvitation> waiting for an answer */
    #[Computed]
    public function invitations()
    {
        return app(InvitationService::class)->pendingForEntity($this->entity);
    }

    public function changeRole(int $userId, string $role, EntityService $entities): void
    {
        $this->resetErrorBag();
        $this->notice = null;
        $person = $this->memberUser($userId);

        try {
            $entities->addMember(auth()->user(), $this->entity, $person, $role);
        } catch (AuthorizationException) {
            $this->notice = 'You cannot change that person or give that role.';

            return;
        } catch (ValidationException $e) {
            $this->notice = $this->firstError($e);
            unset($this->members);

            return;
        }

        unset($this->members);
        $this->notice = $person->name.' is now '.$role.'.';
    }

    public function remove(int $userId, EntityService $entities): mixed
    {
        $this->resetErrorBag();
        $this->notice = null;
        $person = $this->memberUser($userId);
        $leaving = $person->is(auth()->user());

        try {
            $entities->removeMember(auth()->user(), $this->entity, $person);
        } catch (AuthorizationException) {
            $this->notice = 'You cannot remove that person.';

            return null;
        } catch (ValidationException $e) {
            $this->notice = $this->firstError($e);

            return null;
        }

        unset($this->members);

        if ($leaving) {
            session()->flash('bz_notice', 'You left '.$this->entity->name.'.');

            return $this->redirect(pulse_route_first(['floor', 'dashboard']));
        }

        $this->notice = $person->name.' was removed.';

        return null;
    }

    public function render(): View
    {
        return view('livewire.pulse.business.team-manager');
    }

    private function memberUser(int $userId): User
    {
        $member = $this->entity->members()->where('user_id', $userId)->firstOrFail();

        return User::query()->findOrFail($member->user_id);
    }
}
