<?php

namespace App\Livewire\Pulse;

use App\Enums\ReportReason;
use App\Models\PulseComment;
use App\Models\PulsePost;
use App\Models\PulseReport;
use App\Models\User;
use App\Support\PulseThrottle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * "Report" on a post, a comment or a profile. One report per person per
 * thing (a unique index backs this), and only for things the reporter can
 * see and did not write. What the reporter typed is shown to moderators
 * without their name.
 */
class ReportButton extends Component
{
    public string $targetType;

    public int $targetId;

    public bool $open = false;

    public string $reason = '';

    public string $note = '';

    public function mount(string $targetType, int $targetId): void
    {
        $this->targetType = $targetType;
        $this->targetId = $targetId;

        $this->target();
    }

    /**
     * The reported model, or a 404 when it does not exist or the viewer
     * cannot see it, or a 403 when they may not report it (their own).
     */
    private function target(): Model
    {
        $target = match ($this->targetType) {
            'post' => PulsePost::with('community')->find($this->targetId),
            'comment' => PulseComment::with('post.community')->find($this->targetId),
            'user' => User::find($this->targetId),
            default => null,
        };

        abort_if($target === null, 404);

        if (! $target instanceof User) {
            abort_unless(Gate::allows('view', $target), 404);
        }

        Gate::authorize('report', $target);

        return $target;
    }

    #[Computed]
    public function alreadyReported(): bool
    {
        return PulseReport::where('reporter_id', auth()->id())
            ->where('reportable_type', $this->reportableClass())
            ->where('reportable_id', $this->targetId)
            ->exists();
    }

    private function reportableClass(): string
    {
        return match ($this->targetType) {
            'post' => PulsePost::class,
            'comment' => PulseComment::class,
            default => User::class,
        };
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
        $this->resetErrorBag();
    }

    public function submit(): void
    {
        $target = $this->target();

        PulseThrottle::hit('report');

        $this->validate([
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'note' => ['nullable', 'string', 'max:500', Rule::requiredIf($this->reason === ReportReason::Other->value)],
        ], [
            'reason.required' => 'Pick the closest reason.',
            'note.required' => 'Tell us a little about what is wrong.',
        ]);

        try {
            // Savepoint when already inside a transaction (see PulsePost::toggleReaction).
            DB::transaction(fn () => PulseReport::create([
                'reporter_id' => auth()->id(),
                'reportable_type' => $target::class,
                'reportable_id' => $target->getKey(),
                'community_id' => match (true) {
                    $target instanceof PulsePost => $target->community_id,
                    $target instanceof PulseComment => $target->post->community_id,
                    default => null,
                },
                'reason' => $this->reason,
                'details' => $this->note !== '' ? $this->note : null,
                'status' => PulseReport::OPEN,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Already reported: same end state as success, nothing new stored.
        }

        $this->open = false;
        $this->reset(['reason', 'note']);
        unset($this->alreadyReported);
    }

    public function render(): View
    {
        return view('livewire.pulse.report-button');
    }
}
