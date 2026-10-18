<?php

namespace Tests\Feature\Pulse;

use App\Events\CommentPosted;
use App\Events\PostPublished;
use App\Models\User;
use App\Providers\BroadcastServiceProvider;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

/**
 * The public 'pulse-feed' channel carried id/title/type/author/community
 * name of every published post to anyone who could open a websocket,
 * signed in or not -- including posts in private communities.
 */
class RealtimeLeakageTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // See BroadcastChannelAuthorizationTest: the null driver authorizes
        // everything, so use a real driver and re-register the channels.
        config(['broadcasting.default' => 'reverb']);
        (new BroadcastServiceProvider($this->app))->boot();
    }

    private function authRequest(?User $user, string $channelName): TestResponse
    {
        $request = $user ? $this->actingAs($user) : $this;

        return $request->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channelName,
        ]);
    }

    public function test_a_public_post_is_broadcast_on_an_authenticated_private_channel_not_a_public_one(): void
    {
        $post = $this->postIn(null, ['title' => 'A public title that must not ride a public channel']);

        $channels = (new PostPublished($post))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-pulse.feed', $channels[0]->name);
    }

    public function test_the_payload_carries_only_the_fields_a_feed_refresh_needs(): void
    {
        $community = $this->publicCommunity(['name' => 'Open Garden Club']);
        $post = $this->postIn($community, ['title' => 'A public title that must not be broadcast at all']);

        $payload = (new PostPublished($post))->broadcastWith();

        $this->assertSame(['author_name', 'community_name', 'id', 'type'], collect($payload)->keys()->sort()->values()->all());
        $this->assertSame('Open Garden Club', $payload['community_name']);
        $this->assertSame($post->author->name, $payload['author_name']);
        $this->assertStringNotContainsString('A public title', json_encode($payload));
    }

    public function test_a_private_community_post_never_reaches_the_global_feed_channel(): void
    {
        $community = $this->privateCommunity(['name' => 'Vault Group Private Room']);
        $post = $this->postIn($community, ['title' => 'Quarterly layoffs planning for the vault group']);

        $event = new PostPublished($post);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-community.{$community->id}", $channels[0]->name);

        $payload = $event->broadcastWith();
        $this->assertNull($payload['community_name']);
        $this->assertStringNotContainsString('Vault Group', json_encode($payload));
        $this->assertStringNotContainsString('layoffs', json_encode($payload));
    }

    public function test_the_feed_channel_is_open_to_any_signed_in_user(): void
    {
        $this->authRequest($this->pulseUser(), 'private-pulse.feed')->assertOk();
    }

    public function test_the_feed_channel_refuses_a_guest(): void
    {
        // A guest request, in its own test: actingAs() would persist.
        $this->authRequest(null, 'private-pulse.feed')->assertForbidden();
    }

    public function test_the_community_channel_is_for_members_only(): void
    {
        $community = $this->privateCommunity();
        $member = $this->joined($community);
        $outsider = $this->pulseUser();

        $this->authRequest($member, "private-community.{$community->id}")->assertOk();
        $this->authRequest($outsider, "private-community.{$community->id}")->assertForbidden();
    }

    public function test_the_community_channel_refuses_a_guest(): void
    {
        $community = $this->privateCommunity();

        $this->authRequest(null, "private-community.{$community->id}")->assertForbidden();
    }

    public function test_the_community_channel_fails_closed_on_unknown_or_malformed_ids(): void
    {
        $user = $this->pulseUser();

        $this->authRequest($user, 'private-community.999999')->assertForbidden();
        $this->authRequest($user, 'private-community.not-a-number')->assertForbidden();
    }

    public function test_a_removed_member_loses_the_community_channel(): void
    {
        $community = $this->privateCommunity();
        $member = $this->joined($community);
        $community->removeMember($member);

        $this->authRequest($member, "private-community.{$community->id}")->assertForbidden();
    }

    public function test_events_dispatch_only_after_the_transaction_commits(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new PostPublished($this->postIn(null)));
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new CommentPosted($this->commentOn($this->postIn(null))));
    }

    public function test_the_client_no_longer_listens_on_a_public_channel(): void
    {
        $js = file_get_contents(resource_path('js/pulse-realtime.js'));

        $this->assertStringNotContainsString('Echo.channel(', $js);
        $this->assertStringContainsString("Echo.private('pulse.feed')", $js);
    }
}
