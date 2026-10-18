<?php

namespace App\Jobs;

use App\Models\PulsePost;
use App\Services\AiModerationService;
use App\Services\ModerationUnavailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Automated review of a new post, off the web request. The HTTP call itself
 * is capped at services.anthropic.timeout (10s); the job gets three tries
 * with backoff, and when they run out -- or there is no key at all -- the
 * post is held for a human. It is never approved by default.
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

        if (! $moderation->isConfigured()) {
            // Retrying cannot help.
            $moderation->holdForHumanReview($post, 'no API key configured');

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
