<?php

namespace Tests\Feature\Pulse;

use App\Livewire\Pulse\FollowButton;
use App\Models\User;
use App\Notifications\NewFollower;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class FollowButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_follow_another_user(): void
    {
        $target = User::factory()->withPersonalTeam()->create();
        $follower = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($follower)
            ->test(FollowButton::class, ['user' => $target])
            ->call('toggleFollow');

        $this->assertDatabaseHas('pulse_followers', [
            'follower_id' => $follower->id,
            'following_id' => $target->id,
        ]);
        $this->assertTrue($follower->fresh()->isFollowing($target));
    }

    public function test_following_toggles_off_on_a_second_call(): void
    {
        $target = User::factory()->withPersonalTeam()->create();
        $follower = User::factory()->withPersonalTeam()->create();

        $component = Livewire::actingAs($follower)->test(FollowButton::class, ['user' => $target]);

        $component->call('toggleFollow');
        $this->assertTrue($follower->fresh()->isFollowing($target));

        $component->call('toggleFollow');
        $this->assertFalse($follower->fresh()->isFollowing($target));
    }

    public function test_a_user_cannot_follow_themselves(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($user)
            ->test(FollowButton::class, ['user' => $user])
            ->call('toggleFollow')
            ->assertStatus(422);

        $this->assertDatabaseCount('pulse_followers', 0);
    }

    public function test_toggling_dispatches_the_cross_component_browser_event(): void
    {
        $target = User::factory()->withPersonalTeam()->create();
        $follower = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($follower)
            ->test(FollowButton::class, ['user' => $target])
            ->call('toggleFollow')
            ->assertDispatched('follow-toggled', userId: $target->id);
    }

    public function test_following_a_user_sends_them_a_new_follower_notification(): void
    {
        Notification::fake();

        $target = User::factory()->withPersonalTeam()->create();
        $follower = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($follower)
            ->test(FollowButton::class, ['user' => $target])
            ->call('toggleFollow');

        Notification::assertSentTo($target, NewFollower::class, fn (NewFollower $n) => $n->follower->is($follower));
    }

    public function test_unfollowing_does_not_send_a_notification(): void
    {
        Notification::fake();

        $target = User::factory()->withPersonalTeam()->create();
        $follower = User::factory()->withPersonalTeam()->create();
        $follower->following()->attach($target->id);

        Livewire::actingAs($follower)
            ->test(FollowButton::class, ['user' => $target])
            ->call('toggleFollow');

        Notification::assertNotSentTo($target, NewFollower::class);
    }
}
