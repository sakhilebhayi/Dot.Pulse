<?php

namespace App\Livewire\Pulse\Business;

use App\Livewire\Pulse\Business\Support\HandlesServiceErrors;
use App\Models\PulseEntity;
use App\Models\PulsePlace;
use App\Models\PulseRole;
use App\Services\Pulse\Discovery\Places\PlaceRepository;
use App\Services\Pulse\Discovery\Taxonomy;
use App\Services\Pulse\Entities\EntityService;
use App\Services\Pulse\Entities\RoleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The Roles tab: the page's openings and the editor for one, plus the hiring signal (meeting people before there is a
 * vacancy). Roles go through RoleService; the hiring signal goes through EntityService::update, which only an owner or
 * an admin may do, and this screen says so instead of hiding the reason.
 */
class RoleManager extends Component
{
    use HandlesServiceErrors;

    public int $entityId;

    public string $mode = 'list';

    public ?int $roleId = null;

    public string $title = '';

    public string $summary = '';

    public string $description = '';

    public string $employment_type = 'full_time';

    public string $work_mode = 'hybrid';

    public string $seniority = '';

    public ?int $place_id = null;

    public string $place_query = '';

    public string $skills_text = '';

    public string $pay_text = '';

    public string $apply_mode = 'on_pulse';

    public string $apply_url = '';

    public string $closes_at = '';

    public string $hiring_signal = 'none';

    public string $hiring_skills_text = '';

    public ?string $notice = null;

    public function mount(PulseEntity $entity): void
    {
        Gate::authorize('manageListings', $entity);
        $this->entityId = $entity->id;
        $this->hiring_signal = (string) $entity->hiring_signal;
        $this->hiring_skills_text = implode(', ', (array) $entity->hiring_skills);
    }

    #[Computed]
    public function entity(): PulseEntity
    {
        return PulseEntity::query()->findOrFail($this->entityId);
    }

    #[Computed]
    public function roles()
    {
        return $this->entity->roles()->latest('id')->get();
    }

    #[Computed]
    public function canSignal(): bool
    {
        return Gate::allows('update', $this->entity);
    }

    /** @return list<array{id: int, label: string}> */
    #[Computed]
    public function placeResults(): array
    {
        $q = trim($this->place_query);

        if ($this->place_id !== null || mb_strlen($q) < 2) {
            return [];
        }

        $places = app(PlaceRepository::class);

        return $places->search($q, 6)->map(fn (PulsePlace $p) => ['id' => $p->id, 'label' => $places->label($p)])->all();
    }

    /** @return list<array{slug: string, label: string, group: string}> */
    #[Computed]
    public function skillResults(): array
    {
        $parts = array_map('trim', explode(',', $this->skills_text));
        $last = end($parts) ?: '';

        return mb_strlen($last) < 2 ? [] : app(Taxonomy::class)->suggest($last, 'skill', 6);
    }

    public function addSkill(string $label): void
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $this->skills_text))));
        array_pop($parts);
        $parts[] = $label;
        $this->skills_text = implode(', ', $parts).', ';
    }

    public function newRole(): void
    {
        $this->authorizeManage();
        $this->resetForm();
        $this->mode = 'edit';
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();
        $r = $this->find($id);

        $this->resetForm();
        $this->roleId = $r->id;
        $this->title = (string) $r->title;
        $this->summary = (string) $r->summary;
        $this->description = (string) $r->description;
        $this->employment_type = (string) $r->employment_type;
        $this->work_mode = (string) $r->work_mode;
        $this->seniority = (string) $r->seniority;
        $this->place_id = $r->place_id;
        $this->place_query = $r->place ? app(PlaceRepository::class)->label($r->place) : '';
        $this->skills_text = implode(', ', (array) $r->skills);
        $this->pay_text = (string) $r->pay_text;
        $this->apply_mode = (string) $r->apply_mode;
        $this->apply_url = (string) $r->apply_url;
        $this->closes_at = $r->closes_at ? $r->closes_at->format('Y-m-d') : '';
        $this->mode = 'edit';
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->mode = 'list';
    }

    public function updatedPlaceQuery(): void
    {
        $this->place_id = null;
    }

    public function choosePlace(int $id): void
    {
        $place = app(PlaceRepository::class)->find($id);

        if ($place) {
            $this->place_id = $place->id;
            $this->place_query = app(PlaceRepository::class)->label($place);
        }
    }

    public function save(RoleService $roles, bool $publish = false): void
    {
        $this->authorizeManage();
        $this->resetErrorBag();
        $this->notice = null;

        $user = auth()->user();
        $data = $this->payload();

        try {
            $role = $this->roleId === null
                ? $roles->create($user, $this->entity, $data)
                : $roles->update($user, $this->find($this->roleId), $data);

            $this->roleId = $role->id;

            if ($publish) {
                $role = $roles->publish($user, $role);
            }
        } catch (ValidationException $e) {
            $this->fail($e, ['entity' => 'title', 'role' => 'title']);

            return;
        }

        unset($this->roles, $this->entity);
        $this->notice = $this->saidAbout($role, $publish);
        $this->resetForm();
        $this->mode = 'list';
    }

    public function savePublish(RoleService $roles): void
    {
        $this->save($roles, publish: true);
    }

    public function pause(int $id, RoleService $roles): void
    {
        $this->act(fn () => $roles->pause(auth()->user(), $this->find($id)), 'Paused. It is off the floor until you publish it again.');
    }

    public function publish(int $id, RoleService $roles): void
    {
        $this->act(fn () => $this->saidAbout($roles->publish(auth()->user(), $this->find($id)), true));
    }

    public function close(int $id, RoleService $roles): void
    {
        $this->act(fn () => $roles->close(auth()->user(), $this->find($id), 'closed'), 'Closed. People who applied still see how it ended.');
    }

    public function markFilled(int $id, RoleService $roles): void
    {
        $this->act(fn () => $roles->close(auth()->user(), $this->find($id), 'filled'), 'Marked as filled. Well done.');
    }

    public function delete(int $id, RoleService $roles): void
    {
        $this->act(fn () => $roles->delete(auth()->user(), $this->find($id)), 'Deleted.');
    }

    public function saveSignal(EntityService $entities): void
    {
        $this->resetErrorBag();
        $this->notice = null;

        try {
            $entities->update(auth()->user(), $this->entity, [
                'hiring_signal' => $this->hiring_signal,
                'hiring_skills' => array_values(array_filter(array_map('trim', explode(',', $this->hiring_skills_text)))),
            ]);
        } catch (AuthorizationException) {
            $this->addError('hiring_signal', 'Only an owner or an admin can change the hiring signal.');

            return;
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }

        unset($this->entity);
        $this->notice = match ($this->hiring_signal) {
            'none' => 'Hiring signal off.',
            'building' => 'Signal on: building a team. Professionals whose skills match can find you.',
            default => 'Signal on: hiring now. Professionals whose skills match can find you.',
        };
    }

    public function render(): View
    {
        return view('livewire.pulse.business.role-manager');
    }

    // ---- internals ----------------------------------------------------------------------------------------

    private function authorizeManage(): void
    {
        Gate::authorize('manageListings', $this->entity);
    }

    private function find(int $id): PulseRole
    {
        return $this->entity->roles()->with('place')->findOrFail($id);
    }

    private function act(callable $do, ?string $done = null): void
    {
        $this->authorizeManage();
        $this->notice = null;

        try {
            $result = $do();
        } catch (ValidationException $e) {
            $this->notice = $this->firstError($e);
            unset($this->roles);

            return;
        }

        unset($this->roles);
        $this->notice = is_string($result) ? $result : $done;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'title' => $this->title,
            'summary' => $this->summary ?: null,
            'description' => $this->description ?: null,
            'employment_type' => $this->employment_type,
            'work_mode' => $this->work_mode,
            'seniority' => $this->seniority ?: null,
            'place_id' => $this->place_id,
            'skills' => array_values(array_filter(array_map('trim', explode(',', $this->skills_text)))),
            'pay_text' => $this->pay_text ?: null,
            'apply_mode' => $this->apply_mode,
            'apply_url' => $this->apply_mode === 'external_url' ? ($this->apply_url ?: null) : null,
            'closes_at' => $this->closes_at ?: null,
        ];
    }

    private function saidAbout(PulseRole $r, bool $published): string
    {
        if (! $published && $r->status !== 'live') {
            return 'Saved as a draft. Publish it when it is ready.';
        }

        return match ($r->moderation) {
            'approved' => 'Published. It is on the floor.',
            'removed' => 'A moderator removed this role.',
            default => 'Saved. Waiting for a human: it is not on the floor until a person has looked at it.',
        };
    }

    private function resetForm(): void
    {
        $this->reset(['roleId', 'title', 'summary', 'description', 'employment_type', 'work_mode', 'seniority', 'place_id', 'place_query', 'skills_text', 'pay_text', 'apply_mode', 'apply_url', 'closes_at']);
        $this->resetErrorBag();
    }
}
