<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an author that a moderator removed their post or comment. It names
 * neither the moderator nor anyone who reported the content.
 */
class ContentRemoved extends Notification
{
    /** @param 'post'|'comment' $content */
    public function __construct(public string $content) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'content_removed',
            'content' => $this->content,
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
        return 'content_removed';
    }
}
