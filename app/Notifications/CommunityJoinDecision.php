<?php

namespace App\Notifications;

use App\Models\Community;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the person who asked to join. Deliberately does not say which
 * manager decided.
 */
class CommunityJoinDecision extends Notification
{
    public function __construct(public Community $community, public bool $approved) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'community_join_decision',
            'community_id' => $this->community->id,
            'community_name' => $this->community->name,
            'community_slug' => $this->community->slug,
            'approved' => $this->approved,
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
        return 'community_join_decision';
    }
}
