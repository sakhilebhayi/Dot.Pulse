<?php

namespace Tests\Feature\Analytics;

use App\Models\PulseComment;
use App\Models\PulsePost;
use App\Models\PulseReaction;
use App\Models\User;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

class MetricsCollectorTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-11-05 09:30:00', 'UTC');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function collect(): array
    {
        return app(MetricsCollector::class)->collect($this->now);
    }

    private function published(array $attrs = []): PulsePost
    {
        return PulsePost::factory()->create($attrs + ['status' => 'published', 'published_at' => $this->now->copy()->subDay()]);
    }

    private function react(User $user, $reactable, ?Carbon $at = null): PulseReaction
    {
        $reaction = PulseReaction::create([
            'user_id' => $user->id, 'reactable_type' => $reactable::class, 'reactable_id' => $reactable->id, 'emoji' => '👍',
        ]);
        $reaction->forceFill(['created_at' => $at ?? $this->now->copy()->subDay()])->save();

        return $reaction;
    }

    private function commentAt(PulsePost $post, User $user, ?Carbon $at = null): PulseComment
    {
        $comment = PulseComment::factory()->create(['pulse_post_id' => $post->id, 'user_id' => $user->id]);
        $comment->forceFill(['created_at' => $at ?? $this->now->copy()->subDay()])->save();

        return $comment;
    }

    public function test_an_empty_platform_reports_real_zeros_for_exactly_the_three_contract_keys(): void
    {
        $metrics = $this->collect();

        $this->assertSame(['posts_7d' => 0, 'active_users_7d' => 0, 'held_posts' => 0], $metrics);
    }

    public function test_every_value_is_an_integer(): void
    {
        $this->published();

        foreach ($this->collect() as $key => $value) {
            $this->assertIsInt($value, $key);
        }
    }

    public function test_posts_7d_counts_only_published_non_deleted_posts_in_the_window(): void
    {
        $this->published();
        $this->published();
        $this->published(['published_at' => $this->now->copy()->subDays(6)]);
        $this->published(['published_at' => $this->now->copy()->subDays(8)]);
        PulsePost::factory()->create(['status' => 'pending']);
        PulsePost::factory()->create(['status' => 'flagged']);
        PulsePost::factory()->create(['status' => 'removed']);
        PulsePost::factory()->create(['status' => 'draft']);
        $this->published()->delete();

        $this->assertSame(3, $this->collect()['posts_7d']);
    }

    public function test_the_window_edge_is_exactly_seven_days_to_the_second(): void
    {
        $this->published(['published_at' => $this->now->copy()->subDays(7)]);
        $this->published(['published_at' => $this->now->copy()->subDays(7)->subSecond()]);
        $this->published(['published_at' => $this->now->copy()->addSecond()]);

        $this->assertSame(1, $this->collect()['posts_7d']);
    }

    public function test_the_window_is_computed_in_utc_whatever_zone_the_caller_passes(): void
    {
        // 2026-11-05 11:30 in Johannesburg is the same instant as 09:30 UTC.
        $this->published(['published_at' => Carbon::parse('2026-10-29 09:30:00', 'UTC')]);
        $this->published(['published_at' => Carbon::parse('2026-10-29 09:29:59', 'UTC')]);

        $metrics = app(MetricsCollector::class)->collect(Carbon::parse('2026-11-05 11:30:00', 'Africa/Johannesburg'));

        $this->assertSame(1, $metrics['posts_7d']);
    }

    public function test_posts_in_private_communities_are_counted_as_posts_only_never_described(): void
    {
        $this->published(['community_id' => $this->privateCommunity()->id]);
        $this->published(['community_id' => $this->publicCommunity()->id]);

        $this->assertSame(2, $this->collect()['posts_7d']);
    }

    public function test_held_posts_is_the_current_flagged_queue_not_a_window(): void
    {
        PulsePost::factory()->create(['status' => 'flagged']);
        PulsePost::factory()->create(['status' => 'flagged', 'created_at' => $this->now->copy()->subDays(90)]);
        PulsePost::factory()->create(['status' => 'flagged'])->delete();
        PulsePost::factory()->create(['status' => 'pending']);
        PulsePost::factory()->create(['status' => 'removed']);
        $this->published();

        $this->assertSame(2, $this->collect()['held_posts']);
    }

    public function test_a_held_post_is_not_a_published_post(): void
    {
        PulsePost::factory()->create(['status' => 'flagged', 'published_at' => $this->now->copy()->subHour()]);

        $metrics = $this->collect();

        $this->assertSame(0, $metrics['posts_7d']);
        $this->assertSame(1, $metrics['held_posts']);
    }

    public function test_active_users_counts_each_person_once_across_posting_commenting_and_reacting(): void
    {
        $post = $this->published();                          // author A
        $commenter = $this->pulseUser();
        $this->commentAt($post, $commenter);
        $this->commentAt($post, $commenter);                 // same person, twice
        $reactor = $this->pulseUser();
        $this->react($reactor, $post);
        $busy = $this->pulseUser();                          // does all three
        $this->published(['user_id' => $busy->id]);
        $this->commentAt($post, $busy);
        $this->react($busy, $post);

        // author + commenter + reactor + busy
        $this->assertSame(4, $this->collect()['active_users_7d']);
    }

    public function test_reactions_on_comments_count_too(): void
    {
        $post = $this->published();
        $comment = $this->commentAt($post, $post->author);
        $reactor = $this->pulseUser();
        $this->react($reactor, $comment);

        $this->assertSame(2, $this->collect()['active_users_7d']);
    }

    public function test_activity_outside_the_window_does_not_make_anyone_active(): void
    {
        $old = $this->now->copy()->subDays(8);
        $post = $this->published(['published_at' => $old]);
        $this->commentAt($post, $this->pulseUser(), $old);
        $this->react($this->pulseUser(), $post, $old);

        $this->assertSame(0, $this->collect()['active_users_7d']);
    }

    public function test_a_recent_comment_on_an_old_post_still_counts_its_author(): void
    {
        $post = $this->published(['published_at' => $this->now->copy()->subDays(30)]);
        $this->commentAt($post, $this->pulseUser());

        $metrics = $this->collect();

        $this->assertSame(1, $metrics['active_users_7d']);
        $this->assertSame(0, $metrics['posts_7d']);
    }

    public function test_activity_that_is_held_removed_or_deleted_does_not_count(): void
    {
        $held = PulsePost::factory()->create(['status' => 'flagged', 'published_at' => $this->now->copy()->subDay()]);
        $removed = PulsePost::factory()->create(['status' => 'removed', 'published_at' => $this->now->copy()->subDay()]);
        $pending = PulsePost::factory()->create(['status' => 'pending']);
        foreach ([$held, $removed, $pending] as $post) {
            $this->commentAt($post, $this->pulseUser());
            $this->react($this->pulseUser(), $post);
        }

        $live = $this->published();
        $deletedComment = $this->commentAt($live, $this->pulseUser());
        $deletedComment->delete();
        $commentReactor = $this->pulseUser();
        $this->react($commentReactor, $deletedComment);
        $deletedPost = $this->published();
        $deletedPost->delete();
        $this->commentAt($deletedPost, $this->pulseUser());

        // only the live post's author is active
        $this->assertSame(1, $this->collect()['active_users_7d']);
    }

    public function test_it_reads_only_counts_no_row_is_returned_or_kept(): void
    {
        $this->published(['title' => 'S-TITLE-'.str_repeat('a', 60), 'body' => 'S-BODY-'.str_repeat('b', 60)]);

        $encoded = json_encode($this->collect());

        $this->assertStringNotContainsString('S-TITLE-', $encoded);
        $this->assertStringNotContainsString('S-BODY-', $encoded);
        $this->assertSame('{"posts_7d":1,"active_users_7d":1,"held_posts":0}', $encoded);
    }
}
