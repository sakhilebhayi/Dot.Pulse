<?php

namespace Tests\Feature\Pulse;

use App\Models\User;
use App\Notifications\NewFollower;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewFollowerNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_a_database_notification_with_the_followers_details(): void
    {
        $target = User::factory()->withPersonalTeam()->create();
        $follower = User::factory()->withPersonalTeam()->create(['name' => 'Ada Lovelace']);

        $target->notify(new NewFollower($follower));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $target->id,
            'notifiable_type' => User::class,
            'type' => NewFollower::class,
        ]);

        $notification = $target->notifications()->first();
        $this->assertSame('new_follower', $notification->data['type']);
        $this->assertSame($follower->id, $notification->data['follower_id']);
        $this->assertSame('Ada Lovelace', $notification->data['follower_name']);
        $this->assertTrue($notification->unread());
    }

    public function test_the_broadcast_event_name_and_payload_type_are_set_correctly(): void
    {
        $follower = User::factory()->withPersonalTeam()->create();
        $notification = new NewFollower($follower);

        // broadcastAs() is the Echo event name pulse-realtime.js listens
        // for; broadcastType() is a separate hook for the payload's
        // 'type' key -- see this task's own docblock above for why both
        // are required.
        $this->assertSame('notification.new', $notification->broadcastAs());
        $this->assertSame('new_follower', $notification->broadcastType());
    }
}
