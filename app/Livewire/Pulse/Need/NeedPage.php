<?php

namespace App\Livewire\Pulse\Need;

use App\Livewire\Pulse\Business\Support\HandlesServiceErrors;
use App\Models\PulseConversation;
use App\Models\PulseMatch;
use App\Models\PulseNeed;
use App\Models\PulseSignal;
use App\Services\Pulse\Entities\NeedService;
use App\Services\Pulse\Lines\LineService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A need's page. For its owner: status controls, who it was shown to as counts only, and the lines it started. For
 * anyone else it reads like a beat, with a Knock. A "matched" need is only open to people Pulse matched to it.
 */
class NeedPage extends Component
{
    use HandlesServiceErrors;

    #[Locked]
    public int $needId;

    public ?string $notice = null;

    public function mount(int $needId): void
    {
        $this->needId = $needId;
        self::authorised($this->need);
    }

    /** The need, or a 404 for anyone who may not see it (never a 403: the page does not confirm that it exists). */
    public static function authorised(PulseNeed $need): PulseNeed
    {
        abort_unless(Gate::allows('view', $need), 404); // PulseNeedPolicy::view is the only rule

        return $need;
    }

    #[Computed]
    public function need(): PulseNeed
    {
        return PulseNeed::query()->with(['place', 'user:id,name', 'entity:id,slug,name'])->findOrFail($this->needId);
    }

    #[Computed]
    public function isOwner(): bool
    {
        return $this->need->user_id === auth()->id();
    }

    /** @return array{matched: int, seen: int}|null counts only, never who */
    #[Computed]
    public function reach(): ?array
    {
        $signalId = PulseSignal::query()->where('subject_type', 'need')->where('subject_id', $this->needId)->value('id');

        if (! $signalId) {
            return null;
        }

        $matched = PulseMatch::query()->where('signal_id', $signalId)->count();

        if ($matched === 0) {
            return null;
        }

        return ['matched' => $matched, 'seen' => PulseMatch::query()->where('signal_id', $signalId)->whereNotNull('seen_at')->count()];
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function lines(): array
    {
        if (! $this->isOwner) {
            return [];
        }

        $lines = app(LineService::class);
        $user = auth()->user();

        return PulseConversation::query()->visibleTo($user)
            ->where('context_type', 'need')->where('context_id', $this->needId)
            ->latest('id')->limit(30)->get()
            ->map(fn (PulseConversation $c) => $lines->present($user, $c))
            ->all();
    }

    public function close(NeedService $needs, bool $fulfilled = false): void
    {
        $this->run(fn () => $needs->close(auth()->user(), $this->need, $fulfilled),
            $fulfilled ? 'Marked as fulfilled. Glad it worked out.' : 'Closed. Nobody is shown it any more.');
    }

    public function fulfil(NeedService $needs): void
    {
        $this->close($needs, true);
    }

    public function reopen(NeedService $needs): void
    {
        $this->run(fn () => $needs->reopen(auth()->user(), $this->need), 'Open again for another '.(int) config('pulse.entities.need_ttl_days', 30).' days.');
    }

    public function delete(NeedService $needs): mixed
    {
        try {
            $needs->delete(auth()->user(), $this->need);
        } catch (AuthorizationException) {
            $this->notice = 'This is not your need to delete.';

            return null;
        }

        session()->flash('bz_notice', 'Deleted. Lines that started from it keep their own copy.');

        return $this->redirect(pulse_route_first(['floor', 'dashboard']));
    }

    public function render(): View
    {
        $need = $this->need;
        $kinds = NeedForm::kinds();

        return view('livewire.pulse.need.need-page', [
            'need' => $need,
            'kindLabel' => $kinds[$need->kind] ?? 'Need',
            'knockKind' => match ($need->kind) {
                'collab' => 'collab',
                'intro', 'mentor' => 'intro',
                default => 'enquiry',
            },
            'live' => $need->isLive(),
        ]);
    }

    private function run(callable $do, string $done): void
    {
        $this->notice = null;

        try {
            $do();
        } catch (AuthorizationException) {
            $this->notice = 'This is not your need to change.';

            return;
        } catch (ValidationException $e) {
            $this->notice = $this->firstError($e);
            unset($this->need);

            return;
        }

        unset($this->need);
        $this->notice = $done;
    }
}
