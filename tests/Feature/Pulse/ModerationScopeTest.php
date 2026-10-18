<?php

namespace Tests\Feature\Pulse;

use App\Events\PostPublished;
use App\Livewire\Pulse\ModerationQueue;
use App\Models\Community;
use App\Models\PulsePost;
use App\Models\PulsePostEnrichment;
use App\Notifications\ContentRemoved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

/**
 * The moderation role check used to be one global switch: PulseProfile.role
 * of moderator/admin could act on everything and nobody else on anything.
 * Community moderators now moderate their own community; platform
 * moderators moderate platform-wide.
 */
class ModerationScopeTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    private function held(?Community $community, string $body): PulsePost
    {
        $post = PulsePost::factory()->status('flagged')->create([
            'community_id' => $community?->id,
            'body' => $body,
        ]);
        PulsePostEnrichment::create(['pulse_post_id' => $post->id, 'moderation_status' => 'flagged', 'moderation_rationale' => 'Unsure.']);

        return $post;
    }

    public function test_an_ordinary_user_cannot_open_the_queue(): void
    {
        $this->actingAs($this->pulseUser())->get(route('moderation'))->assertForbidden();
        Livewire::actingAs($this->pulseUser())->test(ModerationQueue::class)->assertForbidden();
    }

    public function test_a_member_who_is_not_a_moderator_cannot_open_the_queue(): void
    {
        $community = $this->publicCommunity();
        $member = $this->joined($community);

        Livewire::actingAs($member)->test(ModerationQueue::class)->assertForbidden();
    }

    public function test_a_community_moderator_sees_only_their_communitys_held_posts(): void
    {
        $mine = $this->privateCommunity();
        $moderator = $this->joined($mine, null, 'moderator');
        $this->held($mine, 'Held post inside my own community room.');
        $this->held($this->publicCommunity(), 'Held post inside somebody elses community.');
        $this->held(null, 'Held post with no community whatsoever.');

        Livewire::actingAs($moderator)->test(ModerationQueue::class)
            ->assertSee('Held post inside my own community room.')
            ->assertDontSee('Held post inside somebody elses community.')
            ->assertDontSee('Held post with no community whatsoever.');
    }

    public function test_a_platform_moderator_sees_held_posts_everywhere(): void
    {
        $this->held($this->privateCommunity(), 'Held post inside a private room.');
        $this->held(null, 'Held post with no community whatsoever.');

        Livewire::actingAs($this->platformModerator())->test(ModerationQueue::class)
            ->assertSee('Held post inside a private room.')
            ->assertSee('Held post with no community whatsoever.');
    }

    public function test_a_community_moderator_can_approve_and_reject_inside_their_community(): void
    {
        Event::fake([PostPublished::class]);
        $community = $this->privateCommunity();
        $moderator = $this->joined($community, null, 'moderator');
        $toApprove = $this->held($community, 'Approve me please, I am fine.');
        $toReject = $this->held($community, 'Reject me please, I am not fine.');

        $queue = Livewire::actingAs($moderator)->test(ModerationQueue::class);
        $queue->call('approve', $toApprove->id);
        $queue->call('promptReject', $toReject->id)->set('rejectReason', 'Not for this room.')->call('confirmReject', $toReject->id);

        $this->assertSame('published', $toApprove->fresh()->status);
        $this->assertSame('removed', $toReject->fresh()->status);
        $this->assertDatabaseHas('pulse_moderation_logs', ['target_id' => $toApprove->id, 'action' => 'approve', 'moderator_id' => $moderator->id]);
        $this->assertDatabaseHas('pulse_moderation_logs', ['target_id' => $toReject->id, 'action' => 'reject', 'rationale' => 'Not for this room.']);
        // Approved post of a private community goes out on its own channel only.
        Event::assertDispatched(PostPublished::class, fn ($e) => $e->post->is($toApprove));
    }

    public function test_a_community_moderator_cannot_touch_a_post_outside_their_community(): void
    {
        $mine = $this->privateCommunity();
        $moderator = $this->joined($mine, null, 'moderator');
        $foreign = $this->held($this->publicCommunity(), 'Held post inside somebody elses community.');
        $loose = $this->held(null, 'Held post with no community whatsoever.');

        Livewire::actingAs($moderator)->test(ModerationQueue::class)->call('approve', $foreign->id)->assertForbidden();
        Livewire::actingAs($moderator)->test(ModerationQueue::class)->call('approve', $loose->id)->assertForbidden();

        $this->assertSame('flagged', $foreign->fresh()->status);
        $this->assertSame('flagged', $loose->fresh()->status);
    }

    public function test_the_moderation_link_shows_for_community_and_platform_moderators_only(): void
    {
        $community = $this->publicCommunity();
        $communityModerator = $this->joined($community, null, 'moderator');
        $member = $this->joined($community);

        $this->actingAs($communityModerator)->get('/dashboard')->assertSee(route('moderation'));
        $this->actingAs($this->platformModerator())->get('/dashboard')->assertSee(route('moderation'));
        $this->actingAs($member)->get('/dashboard')->assertDontSee(route('moderation'));
    }

    public function test_the_creator_of_a_community_counts_as_its_moderator(): void
    {
        $creator = $this->pulseUser();
        $community = $this->privateCommunity(['created_by' => $creator->id]);
        $this->held($community, 'Held post inside the creators own room.');

        Livewire::actingAs($creator)->test(ModerationQueue::class)
            ->assertSee('Held post inside the creators own room.');
    }

    public function test_the_queue_distinguishes_an_unavailable_automated_review_from_an_ai_flag(): void
    {
        $post = PulsePost::factory()->status('flagged')->create(['body' => 'Held because the AI could not be reached.']);
        PulsePostEnrichment::create(['pulse_post_id' => $post->id, 'moderation_status' => 'pending']);

        Livewire::actingAs($this->platformModerator())->test(ModerationQueue::class)
            ->assertSee('Automated review unavailable')
            ->assertDontSee('AI recommended reject');
    }

    public function test_removing_a_held_post_tells_its_author_without_naming_the_moderator(): void
    {
        Notification::fake();
        $post = $this->held(null, 'A post that will be rejected by staff.');
        $moderator = $this->platformModerator();

        Livewire::actingAs($moderator)->test(ModerationQueue::class)
            ->call('promptReject', $post->id)->set('rejectReason', 'Spam.')->call('confirmReject', $post->id);

        Notification::assertSentTo($post->author, ContentRemoved::class, function ($n) use ($moderator) {
            return ! str_contains(json_encode($n->toDatabase($n)), (string) $moderator->name);
        });
    }
}
