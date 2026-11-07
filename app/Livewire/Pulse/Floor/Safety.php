<?php

namespace App\Livewire\Pulse\Floor;

use App\Enums\ReportReason;
use App\Models\PulseEntity;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Lines\BlockService;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\MuteService;
use App\Services\Pulse\Lines\ReportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Report, mute and block for one signal. Reporting goes through ReportService (one report per person per thing,
 * a moderator decides). Muting and blocking are aimed at the business behind the signal, or the person for a
 * personal signal. Nothing here is available for an anonymous professional beyond Report: there is no one to name.
 *
 *   <livewire:pulse.floor.safety :signal-id="$signal->id" />
 */
class Safety extends Component
{
    #[Locked]
    public int $signalId;

    public string $reason = '';

    public string $note = '';

    public bool $reported = false;

    public ?string $notice = null;

    public function mount(int $signalId): void
    {
        $this->signalId = $signalId;
    }

    public function report(ReportService $reports): void
    {
        $this->resetErrorBag();

        $signal = $this->signal();

        try {
            $reports->report(auth()->user(), $signal, $this->reason, $this->note !== '' ? $this->note : null);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError(in_array($key, ['reason', 'note'], true) ? $key : 'reason', $messages[0]);
            }

            return;
        } catch (AuthorizationException) {
            $this->notice = 'You cannot report your own listing.';

            return;
        }

        $this->reported = true;
        $this->reset(['reason', 'note']);
        $this->dispatch('toast', message: 'Reported. A moderator will look. They do not tell the other side who reported.', kind: 'good');
    }

    public function mute(MuteService $mutes): void
    {
        [$kind, $value, $name] = $this->target();

        if ($kind === null) {
            return;
        }

        try {
            $mutes->mute(auth()->user(), $kind, (string) $value);
        } catch (LineException $e) {
            $this->notice = $e->getMessage();

            return;
        }

        $this->notice = null;
        $this->dispatch('toast', message: "Muted {$name}. They will not show up on your floor.", kind: 'good');
    }

    public function block(BlockService $blocks): void
    {
        $signal = $this->signal();
        $user = auth()->user();

        try {
            if ($signal->entity_id) {
                $blocks->block($user, PulseEntity::query()->findOrFail($signal->entity_id));
            } elseif ($signal->owner_id && ! $signal->anonymous && $signal->kind !== 'talent') {
                $blocks->block($user, User::query()->findOrFail($signal->owner_id));
            } else {
                return;
            }
        } catch (LineException $e) {
            $this->notice = $e->getMessage();

            return;
        }

        $this->redirectRoute('floor', navigate: false);
    }

    private function signal(): PulseSignal
    {
        return PulseSignal::query()->findOrFail($this->signalId);
    }

    /** @return array{0: ?string, 1: string|int|null, 2: string} kind, value, name */
    private function target(): array
    {
        $signal = $this->signal();

        if ($signal->anonymous || $signal->kind === 'talent') {
            return [null, null, ''];
        }

        if ($signal->entity_id) {
            $e = PulseEntity::query()->find($signal->entity_id, ['id', 'name']);

            return $e ? ['entity', $e->id, $e->name] : [null, null, ''];
        }

        if ($signal->owner_id) {
            $u = User::query()->find($signal->owner_id, ['id', 'name']);

            return $u ? ['user', $u->id, $u->name] : [null, null, ''];
        }

        return [null, null, ''];
    }

    public function render(): View
    {
        $signal = $this->signal();
        [$kind, , $name] = $this->target();

        return view('livewire.pulse.floor.safety', [
            'reasons' => collect(ReportReason::cases())->mapWithKeys(fn (ReportReason $r) => [$r->value => $r->label()])->all(),
            'targetKind' => $kind,
            'targetName' => $name,
            'mine' => $signal->owner_id === auth()->id(),
            'id' => 'safety-'.$this->getId(),
        ]);
    }
}
