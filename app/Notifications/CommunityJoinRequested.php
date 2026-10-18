<?php

namespace App\Notifications;

use App\Models\Community;
use App\Models\User;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a private community's managers when someone asks to join. Carries
 * only what the bell needs: who asked and where to decide.
 */
class CommunityJoinRequested extends Notification
{
    public function __construct(public Community $community, public User $requester) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'community_join_requested',
            'community_id' => $this->community->id,
            'community_name' => $this->community->name,
            'community_slug' => $this->community->slug,
            'requester_id' => $this->requester->id,
            'requester_name' => $this->requester->name,
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
        return 'community_join_requested';
    }
}
