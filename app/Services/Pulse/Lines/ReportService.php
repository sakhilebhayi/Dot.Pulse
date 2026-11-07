<?php

namespace App\Services\Pulse\Lines;

use App\Enums\ReportReason;
use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseMessage;
use App\Models\PulseModerationLog;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseReport;
use App\Models\PulseReview;
use App\Models\PulseRole;
use App\Models\PulseSignal;
use App\Models\User;
use App\Notifications\ReportResolved;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reporting on top of the existing report table and moderation queue. A person reports a line, a message, a
 * person, a page, a listing or a review they can see and did not write; one report per person per thing. A
 * report on a line also closes it for the reporter. When a moderator confirms spam, the sender's knock budget
 * shrinks, so the cost of spamming falls on the spammer (D18).
 */
class ReportService
{
    public function __construct(private readonly KnockBudget $budget) {}

    public function report(User $by, Model $subject, string $reason, ?string $note = null): PulseReport
    {
        $this->authorise($by, $subject);
        Throttle::hit($by, 'report');

        validator(['reason' => $reason, 'note' => $note], [
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'note' => ['nullable', 'string', 'max:500', Rule::requiredIf($reason === ReportReason::Other->value)],
        ], [
            'reason.required' => 'Pick the closest reason.',
            'note.required' => 'Tell us a little about what is wrong.',
        ])->validate();

        $attrs = [
            'reporter_id' => $by->id,
            'reportable_type' => $subject::class,
            'reportable_id' => $subject->getKey(),
        ];

        $existing = PulseReport::query()->where($attrs)->first();
        if ($existing) {
            return $existing;
        }

        try {
            $report = DB::transaction(fn () => PulseReport::create($attrs + [
                'community_id' => null,
                'reason' => $reason,
                'details' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'status' => PulseReport::OPEN,
            ]));
        } catch (UniqueConstraintViolationException) {
            return PulseReport::query()->where($attrs)->firstOrFail();
        }

        $this->closeLineForReporter($by, $subject, $reason);
        AuditLog::record($by, 'report.create', $subject, ['reason' => $reason, 'report' => $report->id]);

        return $report;
    }

    /**
     * A moderator closes a report. When the reason is spam and they confirm it, the sender's knock budget shrinks.
     * The reporter hears the outcome once, with no names in it.
     */
    public function resolve(User $moderator, PulseReport $report, bool $confirmed, ?string $resolution = null): void
    {
        if (! $moderator->isPlatformModerator()) {
            throw new AuthorizationException('Only moderators can do that.');
        }

        if ($report->status !== PulseReport::OPEN) {
            return;
        }

        $report->forceFill([
            'status' => $confirmed ? PulseReport::RESOLVED : PulseReport::DISMISSED,
            'resolution' => $confirmed ? ($resolution ?: 'reviewed') : 'dismissed',
            'resolved_by' => $moderator->id,
            'resolved_at' => now(),
        ])->save();

        $subject = $report->reportable;

        PulseModerationLog::create([
            'moderator_id' => $moderator->id,
            'target_type' => $report->reportable_type,
            'target_id' => $report->reportable_id,
            'action' => $confirmed ? 'review_report' : 'dismiss_report',
            'rationale' => $resolution,
            'is_ai_decision' => false,
        ]);

        if ($confirmed && $report->reason === ReportReason::Spam->value && $subject instanceof Model) {
            $this->shrinkSender($subject, $report->reporter_id);
        }

        AuditLog::record($moderator, $confirmed ? 'report.confirm' : 'report.dismiss', $report, ['reason' => $report->reason]);

        try {
            $report->reporter?->notify(new ReportResolved($confirmed));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function shrinkSender(Model $subject, int $reporterId): void
    {
        $senderId = match (true) {
            $subject instanceof PulseConversation => $subject->initiator_id === $reporterId ? $subject->recipient_id : $subject->initiator_id,
            $subject instanceof PulseMessage => $subject->user_id,
            $subject instanceof User => $subject->id,
            default => null,
        };

        $sender = $senderId ? User::query()->find($senderId) : null;

        if ($sender && $sender->id !== $reporterId) {
            $this->budget->shrink($sender, (int) config('pulse.lines.spam_shrink', 2));
            AuditLog::record(null, 'knock.shrink', $sender, ['by' => (int) config('pulse.lines.spam_shrink', 2)]);
        }
    }

    private function authorise(User $by, Model $subject): void
    {
        $allowed = match (true) {
            $subject instanceof PulseConversation => Gate::forUser($by)->allows('report', $subject),
            $subject instanceof PulseMessage => Gate::forUser($by)->allows('report', $subject),
            $subject instanceof User => ! $subject->is($by),
            $subject instanceof PulseEntity => ! Access::isMemberOf($by, $subject),
            $subject instanceof PulseOffer, $subject instanceof PulseRole => ! Access::isMemberOf($by, $subject->entity_id),
            $subject instanceof PulseNeed => $subject->user_id !== $by->id,
            $subject instanceof PulseSignal => $subject->owner_id !== $by->id,
            $subject instanceof PulseReview => $subject->user_id !== $by->id,
            default => false,
        };

        if (! $allowed) {
            throw new AuthorizationException('You cannot report that.');
        }

        if (! in_array($subject::class, self::reportable(), true)) {
            throw ValidationException::withMessages(['subject' => 'That cannot be reported.']);
        }
    }

    /** @return list<class-string<Model>> */
    public static function reportable(): array
    {
        return [
            PulseConversation::class, PulseMessage::class, User::class, PulseEntity::class,
            PulseOffer::class, PulseRole::class, PulseNeed::class, PulseSignal::class, PulseReview::class,
        ];
    }

    /** The reporter should not have to keep reading what they reported. */
    private function closeLineForReporter(User $by, Model $subject, string $reason): void
    {
        $c = match (true) {
            $subject instanceof PulseConversation => $subject,
            $subject instanceof PulseMessage => $subject->conversation,
            default => null,
        };

        if (! $c || ! in_array($c->status, [PulseConversation::REQUESTED, PulseConversation::OPEN], true)) {
            return;
        }

        $spam = $reason === ReportReason::Spam->value && Access::side($by, $c) === 'recipient';

        PulseConversation::query()->whereKey($c->id)->update([
            'status' => $spam ? PulseConversation::SPAM : PulseConversation::CLOSED,
            'closed_reason' => $spam ? 'spam' : 'reported',
            'closed_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
