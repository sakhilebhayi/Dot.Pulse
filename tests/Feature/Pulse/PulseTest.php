<?php

namespace Tests\Feature\Pulse;

use App\Livewire\Pulse\FollowButton;
use App\Livewire\Pulse\NotificationBell;
use App\Models\Community;
use App\Models\CommunityMembership;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PulseTest extends TestCase
{
    use RefreshDatabase;

    /** InfoDot's launch page, as the browser reports it. */
    private function fromInfodot(): array
    {
        $infodot = parse_url((string) config('ecosystem.infodot.redeem_url', ''), PHP_URL_HOST);

        if (is_string($infodot) && $infodot !== '') {
            return ['Origin' => 'https://'.$infodot];
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return ['Origin' => 'https://'.(str_contains($host, '.') ? substr($host, strpos($host, '.') + 1) : $host)];
    }

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withPersonalTeam()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_dashboard(): void
    {
        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertOk()
            ->assertViewIs('dashboard');
    }

    public function test_the_dashboard_feed_embeds_a_follow_button_for_another_authors_post(): void
    {
        $author = User::factory()->withPersonalTeam()->create();

        PulsePost::create([
            'user_id' => $author->id,
            'team_id' => $author->currentTeam->id,
            'type' => 'discussion',
            'body' => 'A post from someone else',
            'status' => 'published',
        ]);

        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertSeeLivewire(FollowButton::class);
    }

    public function test_the_dashboard_layout_embeds_the_notification_bell(): void
    {
        $this->actingAs($this->user)
            ->get('/dashboard')
            ->assertSeeLivewire(NotificationBell::class);
    }

    public function test_dashboard_creates_profile_if_missing(): void
    {
        $this->assertDatabaseMissing('pulse_profiles', ['user_id' => $this->user->id]);

        $this->actingAs($this->user)->get('/dashboard')->assertOk();

        $this->assertDatabaseHas('pulse_profiles', ['user_id' => $this->user->id]);
    }

    public function test_ecosystem_auth_rejects_missing_token(): void
    {
        $this->post('/auth/ecosystem', [], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
    }

    public function test_pulse_profile_belongs_to_user(): void
    {
        $profile = PulseProfile::create([
            'user_id' => $this->user->id,
            'role' => 'developer',
        ]);

        $this->assertTrue($profile->user->is($this->user));
        $this->assertEquals('developer', $profile->role);
    }

    public function test_profile_add_points(): void
    {
        $profile = PulseProfile::create([
            'user_id' => $this->user->id,
            'community_points' => 10,
        ]);

        $profile->addPoints(5);

        $this->assertEquals(15, $profile->fresh()->community_points);
    }

    public function test_community_can_be_created(): void
    {
        $community = Community::create([
            'created_by' => $this->user->id,
            'name' => 'Fleet Management',
            'slug' => 'fleet-management',
            'description' => 'For fleet operators',
            'industry' => 'Logistics',
            'visibility' => 'public',
        ]);

        $this->assertDatabaseHas('communities', ['slug' => 'fleet-management']);
        $this->assertTrue($community->creator->is($this->user));
    }

    public function test_user_can_join_community(): void
    {
        $community = Community::create([
            'created_by' => $this->user->id,
            'name' => 'Mining',
            'slug' => 'mining',
            'visibility' => 'public',
        ]);

        CommunityMembership::create([
            'community_id' => $community->id,
            'user_id' => $this->user->id,
            'role' => 'member',
        ]);

        $this->assertTrue($community->hasMember($this->user));
    }

    public function test_user_has_joined_communities_relationship(): void
    {
        $community = Community::create([
            'created_by' => $this->user->id,
            'name' => 'Mining Ops',
            'slug' => 'mining-ops',
            'visibility' => 'public',
        ]);

        CommunityMembership::create([
            'community_id' => $community->id,
            'user_id' => $this->user->id,
            'role' => 'member',
        ]);

        $this->assertTrue($this->user->joinedCommunities->contains($community));
    }

    public function test_a_user_receives_broadcast_notifications_on_their_own_short_form_channel(): void
    {
        $this->assertSame('user.'.$this->user->id, $this->user->receivesBroadcastNotificationsOn());
    }

    public function test_pulse_post_can_be_created(): void
    {
        $post = PulsePost::create([
            'user_id' => $this->user->id,
            'team_id' => $this->team->id,
            'type' => 'discussion',
            'body' => 'Has anyone tried using Dot.Agents for invoice automation?',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('pulse_posts', ['type' => 'discussion', 'status' => 'pending']);
        $this->assertTrue($post->author->is($this->user));
    }

    public function test_post_published_scope(): void
    {
        PulsePost::create([
            'user_id' => $this->user->id,
            'team_id' => $this->team->id,
            'type' => 'announcement',
            'body' => 'Published post',
            'status' => 'published',
        ]);

        PulsePost::create([
            'user_id' => $this->user->id,
            'team_id' => $this->team->id,
            'type' => 'announcement',
            'body' => 'Pending post',
            'status' => 'pending',
        ]);

        $this->assertCount(1, PulsePost::published()->get());
    }

    public function test_post_is_published(): void
    {
        $post = PulsePost::create([
            'user_id' => $this->user->id,
            'team_id' => $this->team->id,
            'type' => 'idea',
            'body' => 'An idea post',
            'status' => 'published',
        ]);

        $this->assertTrue($post->isPublished());
    }

    public function test_team_has_communities_relationship(): void
    {
        Community::create([
            'team_id' => $this->team->id,
            'created_by' => $this->user->id,
            'name' => 'Team Community',
            'slug' => 'team-community',
            'visibility' => 'enterprise',
        ]);

        $this->assertCount(1, $this->team->communities);
    }

    public function test_team_has_posts_relationship(): void
    {
        PulsePost::create([
            'user_id' => $this->user->id,
            'team_id' => $this->team->id,
            'type' => 'discussion',
            'body' => 'Team post',
            'status' => 'published',
        ]);

        $this->assertCount(1, $this->team->posts);
    }
}
