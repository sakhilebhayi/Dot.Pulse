<?php

namespace App\Jobs;

use App\Models\PulsePost;
use App\Services\AiModerationService;
use App\Services\ModerationUnavailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Automated review of a new post, off the web request. The deterministic local
 * rules decide first (no model key needed): allow publishes, hold queues for a
 * human, block rejects with a reason. A configured model only adds a second
 * opinion on a hold; its HTTP call is capped at services.anthropic.timeout
 * (10s). If anything unexpected goes wrong the job retries with backoff and, when
 * the tries run out, the post is held for a human -- never approved by default.
 */
class ModeratePost implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** Hard stop for the worker; must exceed the 10s HTTP timeout. */
    public int $timeout = 20;

    /** If the post was deleted before the job ran, drop the job quietly. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public PulsePost $post) {}

    /** @return list<int> seconds between tries */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(AiModerationService $moderation): void
    {
        $post = $this->post->fresh();

        // Already decided (approved by a person, removed, deleted...).
        if ($post === null || $post->status !== 'pending') {
            return;
        }

        try {
            $moderation->moderate($post);
        } catch (ModerationUnavailable $e) {
            if ($this->attempts() >= $this->tries) {
                $moderation->holdForHumanReview($post, $e->getMessage());

                return;
            }

            throw $e;
        }
    }

    /** Timeouts and crashes that skip handle()'s own catch land here. */
    public function failed(\Throwable $e): void
    {
        app(AiModerationService::class)->holdForHumanReview($this->post, $e->getMessage());
    }
}
