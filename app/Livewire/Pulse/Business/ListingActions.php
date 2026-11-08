<?php

namespace App\Livewire\Pulse\Business;

use App\Enums\ReportReason;
use App\Models\PulseEntity;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseRole;
use App\Models\PulseSave;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\Floor;
use App\Services\Pulse\Entities\EntityService;
use App\Services\Pulse\Lines\Access;
use App\Services\Pulse\Lines\BlockService;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\ReportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Follow, Save, Report and Block for a business page, an offer, a role or a need. Every action goes through its own
 * service, which checks the rules again; this component only chooses which to call and says the result in plain words.
 *
 *   <livewire:pulse.business.listing-actions type="offer" :id="$offer->id" />
 */
class ListingActions extends Component
{
    #[Locked]
    public string $type = 'entity';

    #[Locked]
    public int $id = 0;

    public bool $reporting = false;

    public bool $confirmingBlock = false;

    public string $reason = '';

    public string $note = '';

    public ?string $message = null;

    public ?string $messageTone = null;

    public function mount(string $type, int $id): void
    {
        abort_unless(in_array($type, ['entity', 'offer', 'role', 'need'], true), 404);
        abort_unless(auth()->check(), 403);

        $this->type = $type;
        $this->id = $id;

        abort_if($this->subject() === null, 404);
    }

    #[Computed]
    public function subject(): ?Model
    {
        return match ($this->type) {
            'entity' => PulseEntity::query()->find($this->id),
            'offer' => PulseOffer::query()->with('entity')->find($this->id),
            'role' => PulseRole::query()->with('entity')->find($this->id),
            'need' => PulseNeed::query()->find($this->id),
            default => null,
        };
    }

    #[Computed]
    public function entity(): ?PulseEntity
    {
        $s = $this->subject();

        return match (true) {
            $s instanceof PulseEntity => $s,
            $s instanceof PulseOffer, $s instanceof PulseRole => $s->entity,
            default => null,
        };
    }

    /** Your own things: nothing to follow, report or block. */
    #[Computed]
    public function own(): bool
    {
        $user = auth()->user();
        $s = $this->subject();

        if ($s instanceof PulseNeed) {
            return $s->user_id === $user->id;
        }

        return $this->entity !== null && Access::isMemberOf($user, $this->entity);
    }

    #[Computed]
    public function signal(): ?PulseSignal
    {
        $s = $this->subject();

        if ($s === null) {
            return null;
        }

        return PulseSignal::query()
            ->where('subject_type', $this->type)
            ->where('subject_id', $s->getKey())
            ->where('status', 'live')
            ->first();
    }

    #[Computed]
    public function saved(): bool
    {
        return $this->signal !== null
            && PulseSave::query()->where('user_id', auth()->id())->where('saveable_type', 'signal')->where('saveable_id', $this->signal->id)->exists();
    }

    #[Computed]
    public function following(): bool
    {
        return $this->type === 'entity' && $this->entity !== null
            && DB::table('pulse_entity_followers')->where('entity_id', $this->entity->id)->where('user_id', auth()->id())->exists();
    }

    /** @return array<string, string> */
    #[Computed]
    public function reasons(): array
    {
        return collect(ReportReason::cases())->mapWithKeys(fn (ReportReason $r) => [$r->value => $r->label()])->all();
    }

    public function toggleSave(Floor $floor): void
    {
        $signal = $this->signal;

        if ($signal === null) {
            $this->say('This is not on the floor right now, so it cannot be saved.', 'warn');

            return;
        }

        try {
            $this->saved ? $floor->unsave(auth()->user(), $signal) : $floor->saveForLater(auth()->user(), $signal);
        } catch (AuthorizationException) {
            $this->say('You cannot save this.', 'warn');

            return;
        }

        unset($this->saved);
        $this->say($this->saved ? 'Saved. You will find it under Saved.' : 'Removed from Saved.', 'good');
    }

    public function toggleFollow(EntityService $entities): void
    {
        if ($this->entity === null || $this->own) {
            return;
        }

        try {
            $this->following ? $entities->unfollow(auth()->user(), $this->entity) : $entities->follow(auth()->user(), $this->entity);
        } catch (AuthorizationException) {
            $this->say('You cannot follow this page.', 'warn');

            return;
        }

        unset($this->following);
        $this->say($this->following ? 'Following. You will see this page in your round.' : 'Unfollowed.', 'good');
    }

    public function openReport(): void
    {
        $this->reporting = true;
        $this->confirmingBlock = false;
        $this->message = null;
    }

    public function closeReport(): void
    {
        $this->reporting = false;
        $this->resetErrorBag();
    }

    public function report(ReportService $reports): void
    {
        $this->resetErrorBag();

        if ($this->own) {
            return;
        }

        try {
            $reports->report(auth()->user(), $this->subject(), $this->reason, $this->note ?: null);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key, $messages[0]);
            }

            return;
        } catch (AuthorizationException) {
            $this->say('You cannot report this.', 'warn');
            $this->reporting = false;

            return;
        } catch (LineException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }

        $this->reporting = false;
        $this->reason = $this->note = '';
        $this->say('Reported. A person will look. You will not be told who else reported it.', 'good');
    }

    public function askBlock(): void
    {
        $this->confirmingBlock = true;
        $this->reporting = false;
        $this->message = null;
    }

    public function cancelBlock(): void
    {
        $this->confirmingBlock = false;
    }

    public function block(BlockService $blocks): mixed
    {
        if ($this->own) {
            return null;
        }

        $target = $this->subject() instanceof PulseNeed ? User::query()->find($this->subject()->user_id) : $this->entity;

        try {
            $blocks->block(auth()->user(), $target);
        } catch (LineException $e) {
            $this->say($e->getMessage(), 'warn');
            $this->confirmingBlock = false;

            return null;
        }

        session()->flash('bz_notice', 'Blocked. You will not see them again and they are not told.');

        return $this->redirect(pulse_route_first(['floor', 'dashboard']));
    }

    public function render(): View
    {
        return view('livewire.pulse.business.listing-actions');
    }

    private function say(string $text, string $tone): void
    {
        $this->message = $text;
        $this->messageTone = $tone;
    }
}
