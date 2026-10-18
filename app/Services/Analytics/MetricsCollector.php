<?php

namespace App\Services\Analytics;

use App\Models\PulseComment;
use App\Models\PulsePost;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The numbers Dot.Analytics' catalogue defines for the "pulse" platform, and nothing else:
 *
 *   posts_7d         published posts in the last 7 days (held, pending, draft, removed and deleted posts are not published)
 *   active_users_7d  distinct people who published a post, wrote a comment or reacted in the last 7 days
 *   held_posts       posts held for human review right now (status "flagged")
 *
 * Pure counting queries. They return integers only: no names, emails, titles or row data. Pulse is
 * one global network, so these describe the platform as a whole. The window is the 7 x 24 hours up
 * to $asOf, edges inclusive, computed on the UTC clock.
 */
class MetricsCollector
{
    /** @return array{posts_7d: int, active_users_7d: int, held_posts: int} */
    public function collect(CarbonInterface $asOf): array
    {
        $to = Carbon::instance($asOf)->utc();
        $from = $to->copy()->subDays(7);

        return [
            'posts_7d' => $this->posts($from, $to),
            'active_users_7d' => $this->activeUsers($from, $to),
            'held_posts' => PulsePost::query()->where('status', 'flagged')->count(),
        ];
    }

    private function posts(Carbon $from, Carbon $to): int
    {
        return PulsePost::query()
            ->where('status', 'published')
            ->whereBetween('published_at', [$this->stamp($from), $this->stamp($to)])
            ->count();
    }

    private function activeUsers(Carbon $from, Carbon $to): int
    {
        $from = $this->stamp($from);
        $to = $this->stamp($to);

        // Activity only counts on live content: a published, non-deleted post (and a non-deleted comment).
        $live = fn ($q) => $q->where('status', 'published')->whereNull('deleted_at');

        $authors = DB::table('pulse_posts')
            ->select('user_id')
            ->where('status', 'published')->whereNull('deleted_at')
            ->whereBetween('published_at', [$from, $to]);

        $commenters = DB::table('pulse_comments')
            ->select('pulse_comments.user_id')
            ->whereNull('pulse_comments.deleted_at')
            ->whereBetween('pulse_comments.created_at', [$from, $to])
            ->whereIn('pulse_comments.pulse_post_id', $live(DB::table('pulse_posts')->select('id')));

        $postReactors = DB::table('pulse_reactions')
            ->select('user_id')
            ->where('reactable_type', PulsePost::class)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('reactable_id', $live(DB::table('pulse_posts')->select('id')));

        $commentReactors = DB::table('pulse_reactions')
            ->select('user_id')
            ->where('reactable_type', PulseComment::class)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('reactable_id', DB::table('pulse_comments')->select('id')
                ->whereNull('deleted_at')
                ->whereIn('pulse_post_id', $live(DB::table('pulse_posts')->select('id'))));

        $everyone = $authors->union($commenters)->union($postReactors)->union($commentReactors);

        return (int) DB::query()->fromSub($everyone, 'active')->count();
    }

    /** Database timestamps are stored in the app timezone; the window is defined in UTC. */
    private function stamp(Carbon $moment): string
    {
        return $moment->copy()->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }
}
