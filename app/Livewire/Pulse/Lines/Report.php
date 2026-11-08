<?php

namespace App\Livewire\Pulse\Lines;

use App\Enums\ReportReason;
use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseMessage;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseReport;
use App\Models\PulseReview;
use App\Models\PulseRole;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Lines\Access;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\ReportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A small, reusable "Report" for a line, a message, a review, a person, a page or a listing.
 *
 *   <livewire:pulse.lines.report subject-type="conversation" :subject-id="$line->id" />
 *
 * subject-type: conversation | message | review | user | entity | offer | role | need | signal.
 * `start-open` shows the form straight away (a per message report opens it from the message).
 * Everything that matters is decided by ReportService; this only asks and says what happened, in plain words.
 * Moderators see the note, never the reporter's name, and the other side is not told who reported.
 */
class Report extends Component
{
    public const TYPES = [
        'conversation' => PulseConversation::class,
        'message' => PulseMessage::class,
        'review' => PulseReview::class,
        'user' => User::class,
        'entity' => PulseEntity::class,
        'offer' => PulseOffer::class,
        'role' => PulseRole::class,
        'need' => PulseNeed::class,
        'signal' => PulseSignal::class,
    ];

    #[Locked]
    public string $subjectType = 'conversation';

    #[Locked]
    public int $subjectId = 0;

    public bool $open = false;

    public bool $sent = false;

    public string $reason = '';

    public string $note = '';

    public ?string $failure = null;

    public function mount(string $subjectType, int $subjectId, bool $startOpen = false): void
    {
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->subject();
        $this->open = $startOpen;
        $this->sent = $this->already();
    }

    public function toggle(): void
    {
        $this->subject();
        $this->open = ! $this->open;
        $this->failure = null;
        $this->resetErrorBag();
    }

    public function submit(ReportService $reports): void
    {
        $subject = $this->subject();
        $this->failure = null;

        $this->validate([
            'reason' => ['required', 'in:'.implode(',', ReportReason::values())],
            'note' => ['nullable', 'string', 'max:500', 'required_if:reason,other'],
        ], [
            'reason.required' => 'Pick the closest reason.',
            'reason.in' => 'Pick the closest reason.',
            'note.required_if' => 'Tell us a little about what is wrong.',
        ]);

        try {
            $reports->report(auth()->user(), $subject, $this->reason, $this->note !== '' ? $this->note : null);
        } catch (AuthorizationException) {
            $this->failure = 'You cannot report that.';

            return;
        } catch (LineException $e) {
            $this->failure = $e->getMessage();

            return;
        } catch (ThrottleRequestsException $e) {
            $this->failure = $e->getMessage();

            return;
        }

        $this->sent = true;
        $this->open = false;
        $this->reset(['reason', 'note']);
        $this->dispatch('line-reported', type: $this->subjectType, id: $this->subjectId);
        $this->dispatch('toast', message: 'Reported. A moderator will look. You will hear what came of it.', kind: 'good');
    }

    /** The reported thing, or a 404 when it does not exist or the viewer has no business with it. */
    private function subject(): Model
    {
        $class = self::TYPES[$this->subjectType] ?? null;
        abort_if($class === null, 404);

        $subject = $class::query()->find($this->subjectId);
        abort_if($subject === null, 404);

        $user = auth()->user();

        $allowed = match (true) {
            $subject instanceof PulseConversation => Gate::forUser($user)->allows('report', $subject),
            $subject instanceof PulseMessage => Gate::forUser($user)->allows('report', $subject),
            $subject instanceof PulseReview => $subject->conversation !== null && Access::isParticipant($user, $subject->conversation),
            default => true,
        };

        abort_unless($allowed, 404);

        return $subject;
    }

    private function already(): bool
    {
        $class = self::TYPES[$this->subjectType];

        return PulseReport::query()->where([
            'reporter_id' => auth()->id(),
            'reportable_type' => $class,
            'reportable_id' => $this->subjectId,
        ])->exists();
    }

    public function render(): View
    {
        return view('livewire.pulse.lines.report', [
            'reasons' => ReportReason::cases(),
            'noun' => match ($this->subjectType) {
                'conversation' => 'line',
                'message' => 'message',
                'review' => 'review',
                'user' => 'person',
                'entity' => 'page',
                default => 'listing',
            },
        ]);
    }
}
