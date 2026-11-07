<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One transactional Pulse notification (a line request, a reply, a quote, an outcome). The payload is built by
 * App\Services\Pulse\Lines\Notifier and holds plain sentences only: never a name that the recipient may not see
 * (an anonymous professional's identity is not in any payload sent to the page that invited them).
 * The database copy is written synchronously; the email, when preferences allow it, is queued.
 */
class PulseNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<string, mixed>  $payload @param  list<string>  $channels */
    public function __construct(public array $payload, public array $channels = ['database'])
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject((string) ($this->payload['title'] ?? 'Something on Dot.Pulse'))
            ->line((string) ($this->payload['title'] ?? ''));

        if (! empty($this->payload['body'])) {
            $mail->line((string) $this->payload['body']);
        }

        if (! empty($this->payload['url'])) {
            $mail->action('Open it on Pulse', (string) $this->payload['url']);
        }

        return $mail->line('You are getting this because it is about something you are part of on Pulse. Change what Pulse emails you in Settings, Notifications.');
    }
}
