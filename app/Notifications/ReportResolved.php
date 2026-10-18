<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a reporter their report was looked at: acted on (the content was
 * removed) or not. Says nothing else, so it cannot leak who the author is or
 * what the moderator decided beyond that.
 */
class ReportResolved extends Notification
{
    public function __construct(public bool $actioned) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'report_outcome',
            'actioned' => $this->actioned,
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
        return 'report_outcome';
    }
}
