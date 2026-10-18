<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\PulsePost;
use App\Models\User;
use App\Providers\BroadcastServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * App\Providers\BroadcastServiceProvider authorizes the post.{postId}
 * private channel App\Events\CommentPosted broadcasts on. Exercises the
 * real /broadcasting/auth endpoint, per docs/DOT_REALTIME_STANDARD.md
 * (Dot.Mines) §12 -- not the closure in isolation.
 */
class BroadcastChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml forces BROADCAST_CONNECTION=null in the test env so
        // other tests never attempt a real network call -- but the null
        // driver's auth() is a no-op that authorizes everything
        // unconditionally. Switch to a real Pusher-protocol driver for
        // this test class, and re-run channel registration afterward:
        // Broadcast::channel() registers against whichever driver instance
        // is current at call time, and the provider already booted against
        // the null driver during app bootstrap before this setUp() runs.
        config(['broadcasting.default' => 'reverb']);
        (new BroadcastServiceProvider($this->app))->boot();
    }

    private function authRequest(User $user, string $channelName): TestResponse
    {
        return $this->actingAs($user)->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channelName,
        ]);
    }

    private function publishedPost(): PulsePost
    {
        $author = User::factory()->withPersonalTeam()->create();

        return PulsePost::create([
            'user_id' => $author->id,
            'team_id' => $author->currentTeam->id,
            'type' => 'discussion',
            'body' => 'A published post.',
            'status' => 'published',
        ]);
    }

    public function test_any_authenticated_user_can_authorize_a_published_posts_channel(): void
    {
        $post = $this->publishedPost();
        $viewer = User::factory()->withPersonalTeam()->create();

        $this->authRequest($viewer, "private-post.{$post->id}")->assertOk();
    }

    public function test_a_strangers_authorization_fails_for_a_held_posts_channel(): void
    {
        $author = User::factory()->withPersonalTeam()->create();
        $post = PulsePost::create([
            'user_id' => $author->id,
            'team_id' => $author->currentTeam->id,
            'type' => 'discussion',
            'body' => 'Held for review.',
            'status' => 'flagged',
        ]);
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->authRequest($stranger, "private-post.{$post->id}")->assertForbidden();
    }

    public function test_the_authors_own_authorization_succeeds_for_a_held_posts_channel(): void
    {
        $author = User::factory()->withPersonalTeam()->create();
        $post = PulsePost::create([
            'user_id' => $author->id,
            'team_id' => $author->currentTeam->id,
            'type' => 'discussion',
            'body' => 'Held for review.',
            'status' => 'flagged',
        ]);

        $this->authRequest($author, "private-post.{$post->id}")->assertOk();
    }

    public function test_a_private_community_posts_comment_channel_is_members_only(): void
    {
        $community = Community::factory()->private()->create();
        $member = User::factory()->withPersonalTeam()->create();
        $community->addMember($member);
        $post = PulsePost::factory()->create(['community_id' => $community->id]);
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->authRequest($stranger, "private-post.{$post->id}")->assertForbidden();
        $this->authRequest($member, "private-post.{$post->id}")->assertOk();
    }

    public function test_a_nonexistent_post_id_fails_closed(): void
    {
        $viewer = User::factory()->withPersonalTeam()->create();

        $this->authRequest($viewer, 'private-post.999999')->assertForbidden();
    }

    public function test_a_non_numeric_identifier_fails_closed_rather_than_erroring(): void
    {
        $viewer = User::factory()->withPersonalTeam()->create();

        $this->authRequest($viewer, 'private-post.not-a-number')->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_authorize_any_private_channel(): void
    {
        $post = $this->publishedPost();

        $this->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-post.{$post->id}",
        ])->assertForbidden();
    }

    public function test_a_user_can_authorize_their_own_notification_channel(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->authRequest($user, "private-user.{$user->id}")->assertOk();
    }

    public function test_a_user_cannot_authorize_someone_elses_notification_channel(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->authRequest($stranger, "private-user.{$user->id}")->assertForbidden();
    }

    public function test_a_non_numeric_notification_channel_identifier_fails_closed(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->authRequest($user, 'private-user.not-a-number')->assertForbidden();
    }
}
