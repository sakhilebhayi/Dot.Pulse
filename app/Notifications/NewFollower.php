<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * See docs/superpowers/specs/2026-08-17-follow-graph-polish-and-notifications.md
 * and this notification's own test file for why broadcastAs() (the Echo
 * event name) and broadcastType() (the wire payload's 'type' key) are
 * both required and serve different purposes.
 */
class NewFollower extends Notification
{
    public function __construct(public User $follower) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'new_follower',
            'follower_id' => $this->follower->id,
            'follower_name' => $this->follower->name,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    public function broadcastAs(): string
    {
        return 'notification.new';
    }

    public function broadcastType(): string
    {
        return 'new_follower';
    }
}
