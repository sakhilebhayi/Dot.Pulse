<?php

namespace Tests\Feature\Pulse;

use App\Livewire\Pulse\CommunityList;
use App\Livewire\Pulse\HomeFeed;
use App\Livewire\Pulse\PostShow;
use App\Models\CommunityMembership;
use App\Models\PulseComment;
use App\Models\PulseHashtag;
use App\Models\PulsePost;
use App\Models\PulseReaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

class CountersAndIndexesTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    private function recount(bool $dryRun = false): array
    {
        $exit = Artisan::call('pulse:recount', $dryRun ? ['--dry-run' => true] : []);

        return [$exit, Artisan::output()];
    }

    public function test_recount_repairs_every_drifted_counter_and_reports_what_it_changed(): void
    {
        $community = $this->publicCommunity();
        $this->joined($community);
        $this->joined($community);
        $post = $this->postIn(null);
        $comment = $this->commentOn($post);
        $this->commentOn($post, ['parent_id' => $comment->id]);
        PulseReaction::create(['user_id' => $this->pulseUser()->id, 'reactable_type' => PulsePost::class, 'reactable_id' => $post->id, 'emoji' => '👍']);
        PulseReaction::create(['user_id' => $this->pulseUser()->id, 'reactable_type' => PulseComment::class, 'reactable_id' => $comment->id, 'emoji' => '👍']);
        $hashtag = PulseHashtag::create(['name' => 'drifttest', 'posts_count' => 40]);
        $post->hashtags()->attach($hashtag->id);

        // Corrupt them all.
        $community->update(['members_count' => 77]);
        $post->update(['reactions_count' => 55, 'comments_count' => 66]);
        $comment->update(['reactions_count' => 44]);

        [$exit, $output] = $this->recount();

        $this->assertSame(0, $exit);
        $this->assertSame(2, $community->fresh()->members_count);
        $this->assertSame(1, $post->fresh()->reactions_count);
        $this->assertSame(2, $post->fresh()->comments_count);
        $this->assertSame(1, $comment->fresh()->reactions_count);
        $this->assertSame(1, $hashtag->fresh()->posts_count);
        $this->assertStringContainsString('communities.members_count', $output);
        $this->assertStringContainsString('pulse_posts.comments_count', $output);
    }

    public function test_dry_run_reports_drift_without_writing_and_exits_non_zero(): void
    {
        $community = $this->publicCommunity();
        $this->joined($community);
        $community->update(['members_count' => 9]);

        [$exit, $output] = $this->recount(dryRun: true);

        $this->assertSame(1, $exit);
        $this->assertSame(9, $community->fresh()->members_count);
        $this->assertStringContainsString('communities.members_count', $output);

        $this->recount();
        [$exit] = $this->recount(dryRun: true);
        $this->assertSame(0, $exit);
    }

    public function test_recount_ignores_soft_deleted_comments_and_unpublished_posts_in_hashtags(): void
    {
        $post = $this->postIn(null);
        $keep = $this->commentOn($post);
        $gone = $this->commentOn($post);
        $gone->delete();
        $hashtag = PulseHashtag::create(['name' => 'hiddentag', 'posts_count' => 5]);
        $held = $this->postIn(null, ['status' => 'flagged']);
        $held->hashtags()->attach($hashtag->id);

        $this->recount();

        $this->assertSame(1, $post->fresh()->comments_count);
        $this->assertSame(0, $hashtag->fresh()->posts_count);
    }

    /**
     * The drift test: run the real user-facing actions in a messy order and
     * the counters must equal what a recount computes. If any action moved a
     * counter wrongly, --dry-run would report drift.
     */
    public function test_ordinary_use_never_drifts_the_counters(): void
    {
        $community = $this->publicCommunity();
        $alice = $this->pulseUser();
        $bob = $this->pulseUser();
        $post = $this->postIn(null, ['user_id' => $alice->id]);

        // Joins, double joins, leaves by non-members.
        $list = Livewire::actingAs($alice)->test(CommunityList::class);
        $list->call('join', $community->id)->call('join', $community->id)->call('leave', $community->id)->call('leave', $community->id)->call('join', $community->id);
        Livewire::actingAs($bob)->test(CommunityList::class)->call('leave', $community->id)->call('join', $community->id);

        // Reactions on and off, from two people.
        $feedA = Livewire::actingAs($alice)->test(HomeFeed::class);
        $feedA->call('react', $post->id)->call('react', $post->id)->call('react', $post->id);
        Livewire::actingAs($bob)->test(HomeFeed::class)->call('react', $post->id);

        // Comments, replies, reactions on them, deletions with cascades.
        $show = Livewire::actingAs($bob)->test(PostShow::class, ['post' => $post]);
        $show->set('newCommentBody', 'First comment from bob.')->call('postComment');
        $first = PulseComment::firstOrFail();
        $show->set('replyBody', 'A reply from bob.')->call('postReply', $first->id);
        $show->set('newCommentBody', 'Second comment from bob.')->call('postComment');
        $show->call('react', $first->id)->call('react', $first->id)->call('react', $first->id);
        $show->call('deleteComment', $first->id);

        [$exit, $output] = $this->recount(dryRun: true);

        $this->assertSame(0, $exit, "Counters drifted:\n".$output);
        $this->assertSame(2, $community->fresh()->members_count);
        $this->assertSame(1, $post->fresh()->comments_count);
    }

    public function test_toggling_a_reaction_off_never_underflows_a_drifted_counter(): void
    {
        $user = $this->pulseUser();
        $post = $this->postIn(null);
        PulseReaction::create(['user_id' => $user->id, 'reactable_type' => PulsePost::class, 'reactable_id' => $post->id, 'emoji' => '👍']);
        $this->assertSame(0, $post->fresh()->reactions_count); // drifted: row without count

        Livewire::actingAs($user)->test(HomeFeed::class)->call('react', $post->id);

        $this->assertSame(0, $post->fresh()->reactions_count);
        $this->assertDatabaseCount('pulse_reactions', 0);
    }

    public function test_a_membership_removed_twice_decrements_once(): void
    {
        $community = $this->publicCommunity();
        $user = $this->joined($community);
        $other = $this->joined($community);

        $this->assertTrue($community->removeMember($user));
        $this->assertFalse($community->removeMember($user));

        $this->assertSame(1, $community->fresh()->members_count);
        $this->assertSame(1, CommunityMembership::where('community_id', $community->id)->count());
    }

    public function test_the_hot_paths_have_indexes(): void
    {
        $has = function (string $table, array $columns): bool {
            foreach (Schema::getIndexes($table) as $index) {
                if ($index['columns'] === $columns) {
                    return true;
                }
            }

            return false;
        };

        $this->assertTrue($has('communities', ['team_id']), 'communities.team_id');
        $this->assertTrue($has('pulse_posts', ['team_id']), 'pulse_posts.team_id');
        $this->assertTrue($has('pulse_posts', ['community_id', 'status', 'published_at']), 'pulse_posts (community_id, status, published_at)');
        $this->assertTrue($has('community_memberships', ['user_id']), 'community_memberships.user_id');
        $this->assertTrue($has('pulse_reports', ['reportable_type', 'reportable_id']), 'reports polymorphic target');
        $this->assertTrue($has('pulse_reports', ['reporter_id', 'reportable_type', 'reportable_id']), 'reports one per reporter');
        $this->assertTrue($has('pulse_reports', ['community_id', 'status']), 'reports community+status');
        $this->assertTrue($has('pulse_moderation_logs', ['target_type', 'target_id']), 'moderation logs polymorphic target');
        $this->assertTrue($has('pulse_moderation_logs', ['target_type', 'target_id', 'created_at']), 'moderation logs target timeline');
    }

    public function test_a_post_publish_stamps_published_at_once(): void
    {
        $post = PulsePost::factory()->status('pending')->create();
        $this->assertNull($post->published_at);

        $post->update(['status' => 'published']);
        $stamp = $post->fresh()->published_at;
        $this->assertNotNull($stamp);

        $this->travel(5)->minutes();
        $post->update(['body' => 'Edited body that keeps the first publish time.']);
        $this->assertTrue($stamp->equalTo($post->fresh()->published_at));
    }

    public function test_deleting_a_private_community_does_not_publish_its_posts(): void
    {
        $community = $this->privateCommunity();
        $post = $this->postIn($community);

        $community->delete();

        $this->assertDatabaseMissing('pulse_posts', ['id' => $post->id]);
    }

    public function test_the_migrations_roll_back_and_forward_cleanly(): void
    {
        // Guards the FK swaps and index drops in the down() methods, on
        // whichever driver the suite runs on. Three steps: the InfoDot team
        // link, then the two moderation migrations.
        $this->artisan('migrate:rollback', ['--step' => 3])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('teams', 'infodot_team_uuid'));
        $this->assertFalse(Schema::hasTable('community_join_requests'));
        $this->assertFalse(Schema::hasColumn('pulse_reports', 'community_id'));

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('community_join_requests'));
        $this->assertTrue(Schema::hasColumn('teams', 'infodot_team_uuid'));
        $this->assertTrue(Schema::hasColumn('pulse_posts', 'published_at'));
    }
}
