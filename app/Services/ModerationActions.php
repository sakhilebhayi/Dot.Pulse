<?php

namespace App\Services;

use App\Events\PostPublished;
use App\Models\PulseComment;
use App\Models\PulseModerationLog;
use App\Models\PulsePost;
use App\Models\PulseReport;
use App\Models\User;
use App\Notifications\ContentRemoved;
use App\Notifications\ReportResolved;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Every moderator action in one place, so each one writes its log row and
 * sends its notifications the same way whether it started in the held-post
 * queue, the report queue or a post page. Callers authorize; this class
 * does not.
 */
class ModerationActions
{
    public function approvePost(PulsePost $post, User $moderator): bool
    {
        if ($post->status !== 'flagged') {
            return false;
        }

        DB::transaction(function () use ($post, $moderator) {
            $post->update(['status' => 'published']);
            $this->log($moderator, $post, 'approve', null);
        });

        (new HashtagExtractionService)->extractAndAttach($post);
        $this->safely(fn () => PostPublished::dispatch($post));

        return true;
    }

    /** Reject a post held for review: removes it and says why in the log. */
    public function rejectPost(PulsePost $post, User $moderator, string $reason): bool
    {
        if ($post->status !== 'flagged') {
            return false;
        }

        DB::transaction(function () use ($post, $moderator, $reason) {
            $post->update(['status' => 'removed']);
            $this->log($moderator, $post, 'reject', $reason);
        });

        $this->safely(fn () => $post->author->notify(new ContentRemoved('post')));

        return true;
    }

    /** Remove a live post. The row stays; only the log says who and why. */
    public function removePost(PulsePost $post, User $moderator, string $reason): bool
    {
        if ($post->status === 'removed') {
            return false;
        }

        DB::transaction(function () use ($post, $moderator, $reason) {
            $post->update(['status' => 'removed']);
            $this->log($moderator, $post, 'remove', $reason);
        });

        $this->safely(fn () => $post->author->notify(new ContentRemoved('post')));

        return true;
    }

    public function removeComment(PulseComment $comment, User $moderator, string $reason): bool
    {
        if ($comment->trashed()) {
            return false;
        }

        DB::transaction(function () use ($comment, $moderator, $reason) {
            $comment->remove();
            $this->log($moderator, $comment, 'remove', $reason);
        });

        $this->safely(fn () => $comment->author->notify(new ContentRemoved('comment')));

        return true;
    }

    /**
     * Act on every open report about one target: remove post/comment content
     * (reason required), or just mark a profile reviewed.
     *
     * @return int reports closed
     */
    public function resolveReports(Model $target, User $moderator, ?string $reason): int
    {
        $removed = false;

        if ($target instanceof PulsePost) {
            $removed = $this->removePost($target, $moderator, (string) $reason) || $target->status === 'removed';
        } elseif ($target instanceof PulseComment) {
            $removed = $this->removeComment($target, $moderator, (string) $reason) || $target->trashed();
        } else {
            $this->log($moderator, $target, 'review_report', $reason);
        }

        return $this->closeReports($target, $moderator, PulseReport::RESOLVED, $removed ? 'removed' : 'reviewed', $removed);
    }

    /** @return int reports closed */
    public function dismissReports(Model $target, User $moderator): int
    {
        $this->log($moderator, $target, 'dismiss_report', null);

        return $this->closeReports($target, $moderator, PulseReport::DISMISSED, 'dismissed', false);
    }

    private function closeReports(Model $target, User $moderator, string $status, string $resolution, bool $actioned): int
    {
        $reports = PulseReport::open()
            ->where('reportable_type', $target::class)
            ->where('reportable_id', $target->getKey())
            ->get();

        PulseReport::whereKey($reports->modelKeys())->update([
            'status' => $status,
            'resolution' => $resolution,
            'resolved_by' => $moderator->id,
            'resolved_at' => now(),
        ]);

        // Each reporter hears the outcome once, with no names in it.
        User::whereKey($reports->pluck('reporter_id')->unique())->get()->each(
            fn (User $reporter) => $this->safely(fn () => $reporter->notify(new ReportResolved($actioned)))
        );

        return $reports->count();
    }

    private function log(User $moderator, Model $target, string $action, ?string $rationale): void
    {
        PulseModerationLog::create([
            'moderator_id' => $moderator->id,
            'target_type' => $target::class,
            'target_id' => $target->getKey(),
            'action' => $action,
            'rationale' => $rationale !== null ? trim($rationale) : null,
            'is_ai_decision' => false,
        ]);
    }

    /** Notifications and broadcasts are best-effort; the decision is already saved. */
    private function safely(\Closure $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
