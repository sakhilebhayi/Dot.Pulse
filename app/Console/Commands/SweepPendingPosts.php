<?php

namespace App\Console\Commands;

use App\Models\PulsePost;
use App\Services\AiModerationService;
use Illuminate\Console\Command;

/**
 * A post waits in "pending" while the queue worker reviews it. If the worker
 * is down, pending posts stay hidden (fail closed) but nobody would ever
 * look at them. This hands them to the human queue instead.
 */
class SweepPendingPosts extends Command
{
    protected $signature = 'pulse:sweep-pending {--minutes=15 : Hold posts pending for longer than this}';

    protected $description = 'Hold posts stuck in pending for a human moderator';

    public function handle(AiModerationService $moderation): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));
        $held = 0;

        PulsePost::where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->each(function (PulsePost $post) use ($moderation, &$held) {
                $moderation->holdForHumanReview($post, 'review did not finish in time');
                $held++;
            });

        $this->info("Held {$held} stuck post(s) for human review.");

        return self::SUCCESS;
    }
}
