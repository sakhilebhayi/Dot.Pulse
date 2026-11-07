<?php

namespace App\Livewire\Pulse\Business;

use App\Livewire\Pulse\Business\Support\HandlesServiceErrors;
use App\Models\PulseEntity;
use App\Models\PulseEntityMember;
use App\Models\User;
use App\Services\Pulse\Entities\EntityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The Team tab: who can do what on this page. Members must already be Pulse users (they signed in through InfoDot once).
 * The service enforces the rules: an admin can only manage editors and responders, and the last owner is never removed.
 */
class TeamManager extends Component
{
    use HandlesServiceErrors;

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

    public function add(EntityService $entities): void
    {
        $this->resetErrorBag();
        $this->notice = null;
        Gate::authorize('manageMembers', $this->entity);

        $this->validate(['email' => ['required', 'email', 'max:255'], 'role' => ['required', 'in:owner,admin,editor,responder']], [], ['email' => 'email']);

        $key = 'pulse-team-add:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            $this->addError('email', 'That is a lot of people in an hour. Try again later.');

            return;
        }

        RateLimiter::hit($key, 3600);

        $person = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($this->email))])->first();

        if ($person === null) {
            $this->addError('email', 'Nobody on Pulse has that email yet. Ask them to sign in to Pulse through InfoDot once, then add them.');

            return;
        }

        try {
            $entities->addMember(auth()->user(), $this->entity, $person, $this->role);
        } catch (AuthorizationException) {
            $this->addError('role', 'You cannot give that role. Owners can give any role; admins can give editor or responder.');

            return;
        } catch (ValidationException $e) {
            $this->fail($e, ['member' => 'email']);

            return;
        }

        unset($this->members);
        $this->notice = $person->name.' is now '.$this->role.'.';
        $this->email = '';
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
