<?php

namespace App\Livewire\Pulse\Business;

use App\Livewire\Pulse\Business\Support\EntityView;
use App\Livewire\Pulse\Business\Support\HandlesServiceErrors;
use App\Models\PulseEntity;
use App\Models\Team;
use App\Services\Pulse\Entities\InfoDotLink;
use App\Services\Pulse\Entities\VerificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The Verify tab: the ladder as it stands, the InfoDot company link and the registration number claim. It explains what
 * InfoDot does and does not confirm, because the ladder is only worth anything if it never claims more than it knows.
 */
class VerifyPanel extends Component
{
    use HandlesServiceErrors;

    public int $entityId;

    public string $registration_number = '';

    public ?string $team = null;

    public ?string $notice = null;

    public function mount(PulseEntity $entity): void
    {
        Gate::authorize('update', $entity);
        $this->entityId = $entity->id;
        $this->registration_number = (string) $entity->registration_number;
    }

    #[Computed]
    public function entity(): PulseEntity
    {
        return PulseEntity::query()->findOrFail($this->entityId);
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function ladder(): array
    {
        return EntityView::ladder($this->entity);
    }

    #[Computed]
    public function isOwner(): bool
    {
        return $this->entity->memberRole(auth()->user()) === 'owner';
    }

    #[Computed]
    public function linkedCompany(): ?Team
    {
        $ref = $this->entity->infodot_business_ref;

        return $ref ? Team::query()->where('infodot_team_uuid', $ref)->first() : null;
    }

    /** @return array<string, string> uuid => name, companies the person manages in InfoDot that no other page uses */
    #[Computed]
    public function companies(): array
    {
        $user = auth()->user();

        return InfoDotLink::teams($user)
            ->filter(fn (Team $t) => InfoDotLink::manages($user, $t)
                && ! PulseEntity::withTrashed()->where('infodot_business_ref', $t->infodot_team_uuid)->where('id', '!=', $this->entityId)->exists())
            ->mapWithKeys(fn (Team $t) => [$t->infodot_team_uuid => $t->name])
            ->all();
    }

    public function link(VerificationService $verification): void
    {
        $this->resetErrorBag();
        $this->notice = null;

        $team = $this->team ? Team::query()->where('infodot_team_uuid', $this->team)->first() : null;

        if ($team === null) {
            $this->addError('team', 'Pick a company first.');

            return;
        }

        try {
            $verification->linkInfoDotCompany(auth()->user(), $this->entity, $team);
        } catch (AuthorizationException) {
            $this->addError('team', 'You cannot link a company on this page.');

            return;
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }

        $this->refreshAll();
        $this->notice = 'Linked. Your ladder now shows that you belong to '.$team->name.' in InfoDot.';
    }

    public function unlink(VerificationService $verification): void
    {
        $this->resetErrorBag();

        try {
            $verification->unlinkInfoDotCompany(auth()->user(), $this->entity);
        } catch (AuthorizationException) {
            $this->notice = 'You cannot change that on this page.';

            return;
        }

        $this->refreshAll();
        $this->notice = 'Unlinked. The InfoDot line is gone from your ladder.';
    }

    public function claim(VerificationService $verification): void
    {
        $this->resetErrorBag();
        $this->notice = null;

        try {
            $verification->claimRegistration(auth()->user(), $this->entity, $this->registration_number);
        } catch (AuthorizationException) {
            $this->addError('registration_number', 'You cannot change that on this page.');

            return;
        } catch (ValidationException $e) {
            $this->fail($e, ['number' => 'registration_number']);

            return;
        }

        $this->refreshAll();
        $this->notice = 'Saved as a claim. It shows as claimed, not checked.';
    }

    public function render(): View
    {
        return view('livewire.pulse.business.verify-panel');
    }

    private function refreshAll(): void
    {
        unset($this->entity, $this->ladder, $this->linkedCompany, $this->companies);
    }
}
