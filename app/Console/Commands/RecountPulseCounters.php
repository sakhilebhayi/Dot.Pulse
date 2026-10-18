<?php

namespace App\Console\Commands;

use App\Models\PulseComment;
use App\Models\PulsePost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Every denormalised counter, recomputed from the rows it summarises. The
 * app keeps these correct with atomic increments inside transactions; this
 * is the safety net (and the drift detector: --dry-run exits 1 when any
 * counter is wrong, so it can run from cron and alert).
 */
class RecountPulseCounters extends Command
{
    protected $signature = 'pulse:recount {--dry-run : Report drift and change nothing; exit 1 if any counter is wrong}';

    protected $description = 'Recompute members, reactions, comments and hashtag counters from their source rows';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $totalDrift = 0;

        foreach ($this->counters() as [$label, $table, $column, $subquery, $bindings]) {
            $where = "{$table}.{$column} <> ({$subquery})";

            $drifted = DB::table($table)->whereRaw($where, $bindings)->count();
            $totalDrift += $drifted;

            if ($drifted > 0 && ! $dryRun) {
                DB::update(
                    "update {$table} set {$column} = ({$subquery}) where {$where}",
                    array_merge($bindings, $bindings),
                );
            }

            $this->line(sprintf(
                '%-34s %s',
                $label,
                $drifted === 0 ? 'ok' : ($dryRun ? "{$drifted} wrong" : "{$drifted} corrected"),
            ));
        }

        if ($dryRun && $totalDrift > 0) {
            $this->warn("{$totalDrift} counter value(s) are wrong. Run pulse:recount to fix them.");

            return self::FAILURE;
        }

        $this->info($totalDrift === 0 ? 'All counters match their rows.' : "Corrected {$totalDrift} counter value(s).");

        return self::SUCCESS;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: list<string>}>
     */
    private function counters(): array
    {
        return [
            [
                'communities.members_count', 'communities', 'members_count',
                'select count(*) from community_memberships m where m.community_id = communities.id',
                [],
            ],
            [
                'pulse_posts.reactions_count', 'pulse_posts', 'reactions_count',
                'select count(*) from pulse_reactions r where r.reactable_type = ? and r.reactable_id = pulse_posts.id',
                [PulsePost::class],
            ],
            [
                'pulse_posts.comments_count', 'pulse_posts', 'comments_count',
                'select count(*) from pulse_comments c where c.pulse_post_id = pulse_posts.id and c.deleted_at is null',
                [],
            ],
            [
                'pulse_comments.reactions_count', 'pulse_comments', 'reactions_count',
                'select count(*) from pulse_reactions r where r.reactable_type = ? and r.reactable_id = pulse_comments.id',
                [PulseComment::class],
            ],
            [
                'pulse_hashtags.posts_count', 'pulse_hashtags', 'posts_count',
                'select count(*) from pulse_post_hashtag ph join pulse_posts p on p.id = ph.pulse_post_id where ph.pulse_hashtag_id = pulse_hashtags.id and p.status = ? and p.deleted_at is null',
                ['published'],
            ],
        ];
    }
}
