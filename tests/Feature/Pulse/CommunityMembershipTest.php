<?php

namespace Tests\Feature\Pulse;

use App\Livewire\Pulse\CommunityList;
use App\Livewire\Pulse\CommunityShow;
use App\Livewire\Pulse\NotificationBell;
use App\Models\CommunityJoinRequest;
use App\Models\CommunityMembership;
use App\Notifications\CommunityJoinDecision;
use App\Notifications\CommunityJoinRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

class CommunityMembershipTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    public function test_public_communities_keep_one_click_join_and_leave(): void
    {
        $community = $this->publicCommunity();
        $user = $this->pulseUser();

        Livewire::actingAs($user)->test(CommunityList::class)->call('join', $community->id);

        $this->assertTrue($community->fresh()->isMember($user));
        $this->assertSame(1, $community->fresh()->members_count);

        Livewire::actingAs($user)->test(CommunityList::class)->call('leave', $community->id);

        $this->assertFalse($community->fresh()->isMember($user));
        $this->assertSame(0, $community->fresh()->members_count);
    }

    public function test_joining_a_private_community_from_the_directory_files_a_request_not_a_membership(): void
    {
        Notification::fake();
        $community = $this->privateCommunity();
        $manager = $this->joined($community, null, 'admin');
        $user = $this->pulseUser();

        Livewire::actingAs($user)->test(CommunityList::class)->call('join', $community->id);

        $this->assertFalse($community->fresh()->isMember($user));
        $this->assertSame(1, $community->fresh()->members_count);
        $this->assertDatabaseHas('community_join_requests', [
            'community_id' => $community->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);
        Notification::assertSentTo($manager, CommunityJoinRequested::class);
    }

    public function test_requesting_twice_does_not_notify_managers_twice(): void
    {
        Notification::fake();
        $community = $this->privateCommunity();
        $manager = $this->joined($community, null, 'admin');
        $user = $this->pulseUser();

        $list = Livewire::actingAs($user)->test(CommunityList::class);
        $list->call('join', $community->id)->call('join', $community->id);

        $this->assertSame(1, CommunityJoinRequest::count());
        Notification::assertSentToTimes($manager, CommunityJoinRequested::class, 1);
    }

    public function test_a_non_member_cannot_join_a_private_community_through_its_page(): void
    {
        $community = $this->privateCommunity();

        // The page itself is a 404, and so is a hand-built Livewire mount.
        $this->actingAs($this->pulseUser())->get(route('communities.show', $community))->assertNotFound();

        Livewire::actingAs($this->pulseUser())->test(CommunityShow::class, ['community' => $community])
            ->assertNotFound();
    }

    public function test_a_member_who_loses_membership_cannot_keep_acting_on_the_private_page(): void
    {
        $community = $this->privateCommunity();
        $user = $this->joined($community);

        $component = Livewire::actingAs($user)->test(CommunityShow::class, ['community' => $community])
            ->assertOk();

        $community->removeMember($user);

        $component->call('leave')->assertNotFound();
    }

    public function test_a_manager_approves_a_request_and_the_requester_is_notified(): void
    {
        Notification::fake();
        $community = $this->privateCommunity();
        $manager = $this->joined($community, null, 'admin');
        $user = $this->pulseUser();
        $request = CommunityJoinRequest::create(['community_id' => $community->id, 'user_id' => $user->id]);

        Livewire::actingAs($manager)->test(CommunityShow::class, ['community' => $community])
            ->call('approveRequest', $request->id);

        $this->assertTrue($community->fresh()->isMember($user));
        $this->assertSame(2, $community->fresh()->members_count);
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame($manager->id, $request->fresh()->decided_by);
        Notification::assertSentTo($user, CommunityJoinDecision::class, fn ($n) => $n->approved === true);
    }

    public function test_a_community_moderator_can_deny_and_the_requester_is_notified(): void
    {
        Notification::fake();
        $community = $this->privateCommunity();
        $moderator = $this->joined($community, null, 'moderator');
        $user = $this->pulseUser();
        $request = CommunityJoinRequest::create(['community_id' => $community->id, 'user_id' => $user->id]);

        Livewire::actingAs($moderator)->test(CommunityShow::class, ['community' => $community])
            ->call('denyRequest', $request->id);

        $this->assertFalse($community->fresh()->isMember($user));
        $this->assertSame('denied', $request->fresh()->status);
        Notification::assertSentTo($user, CommunityJoinDecision::class, fn ($n) => $n->approved === false);
    }

    public function test_an_ordinary_member_cannot_decide_requests(): void
    {
        $community = $this->privateCommunity();
        $member = $this->joined($community);
        $user = $this->pulseUser();
        $request = CommunityJoinRequest::create(['community_id' => $community->id, 'user_id' => $user->id]);

        Livewire::actingAs($member)->test(CommunityShow::class, ['community' => $community])
            ->call('approveRequest', $request->id)
            ->assertForbidden();

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertFalse($community->fresh()->isMember($user));
    }

    public function test_a_moderator_of_another_community_cannot_decide_requests_here(): void
    {
        $community = $this->privateCommunity();
        $this->joined($community);
        $other = $this->privateCommunity();
        $outsideModerator = $this->joined($other, null, 'moderator');
        $user = $this->pulseUser();
        $request = CommunityJoinRequest::create(['community_id' => $community->id, 'user_id' => $user->id]);

        // Not a member, so the page is not even reachable.
        Livewire::actingAs($outsideModerator)->test(CommunityShow::class, ['community' => $community])
            ->assertNotFound();

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_a_request_id_from_another_community_cannot_be_approved(): void
    {
        $community = $this->privateCommunity();
        $manager = $this->joined($community, null, 'admin');
        $other = $this->privateCommunity();
        $user = $this->pulseUser();
        $foreign = CommunityJoinRequest::create(['community_id' => $other->id, 'user_id' => $user->id]);

        Livewire::actingAs($manager)->test(CommunityShow::class, ['community' => $community])
            ->call('approveRequest', $foreign->id)
            ->assertNotFound();

        $this->assertFalse($other->fresh()->isMember($user));
    }

    public function test_a_denied_requester_cannot_ask_again_during_the_cooldown(): void
    {
        Notification::fake();
        $community = $this->privateCommunity();
        $manager = $this->joined($community, null, 'admin');
        $user = $this->pulseUser();
        CommunityJoinRequest::create([
            'community_id' => $community->id,
            'user_id' => $user->id,
            'status' => 'denied',
            'decided_by' => $manager->id,
            'decided_at' => now()->subDay(),
        ]);

        Livewire::actingAs($user)->test(CommunityList::class)->call('join', $community->id);

        $this->assertSame('denied', CommunityJoinRequest::first()->status);
        Notification::assertNothingSent();

        $this->travel(config('pulse.join_request_cooldown_days') + 1)->days();

        Livewire::actingAs($user)->test(CommunityList::class)->call('join', $community->id);

        $this->assertSame('pending', CommunityJoinRequest::first()->status);
        Notification::assertSentTo($manager, CommunityJoinRequested::class);
    }

    public function test_leaving_when_not_a_member_does_not_decrement_the_member_count(): void
    {
        $community = $this->publicCommunity();
        $this->joined($community);
        $this->joined($community);
        $this->assertSame(2, $community->fresh()->members_count);
        $outsider = $this->pulseUser();

        Livewire::actingAs($outsider)->test(CommunityList::class)->call('leave', $community->id);

        $this->assertSame(2, $community->fresh()->members_count);
        $this->assertSame(2, CommunityMembership::where('community_id', $community->id)->count());
    }

    public function test_joining_twice_does_not_double_count(): void
    {
        $community = $this->publicCommunity();
        $user = $this->pulseUser();

        $list = Livewire::actingAs($user)->test(CommunityList::class);
        $list->call('join', $community->id)->call('join', $community->id);

        $this->assertSame(1, $community->fresh()->members_count);
    }

    public function test_the_bell_shows_join_requests_and_decisions_with_their_own_wording(): void
    {
        $community = $this->privateCommunity(['name' => 'Vault Group Private Room']);
        $manager = $this->joined($community, null, 'admin');
        $user = $this->pulseUser();
        $user->name = 'Requester With A Memorable Name';
        $user->save();

        $manager->notify(new CommunityJoinRequested($community, $user));
        $user->notify(new CommunityJoinDecision($community, true));

        Livewire::actingAs($manager)->test(NotificationBell::class)
            ->assertSee('Requester With A Memorable Name')
            ->assertSee('asked to join Vault Group Private Room');

        Livewire::actingAs($user)->test(NotificationBell::class)
            ->assertSee('Your request to join Vault Group Private Room was approved');
    }
}
