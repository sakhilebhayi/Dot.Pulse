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
        // Titles and bodies carry words other people wrote (a name, what an enquiry is about). In the subject they are
        // one plain line; in the body they are plain text too: Markdown in them (a link, an image) must not become one.
        // The greeting uses the recipient's own first name only, never a name from the payload: it may be someone they
        // may not see.
        $title = (string) ($this->payload['title'] ?? '');
        $first = trim(explode(' ', trim((string) ($notifiable->name ?? '')))[0] ?? '');

        $mail = (new MailMessage)
            ->subject(self::oneLine($title) !== '' ? self::oneLine($title) : 'Something on Dot.Pulse')
            ->greeting($first !== '' ? 'Hi '.self::oneLine($first).',' : 'Hi,')
            ->salutation('Dot.Pulse')
            ->line(self::literal($title));

        if (! empty($this->payload['body'])) {
            $mail->line(self::literal((string) $this->payload['body']));
        }

        if (! empty($this->payload['url'])) {
            $mail->action('Open it on Pulse', (string) $this->payload['url']);
        }

        return $mail->line('You are getting this because it is about something you are part of on Pulse. Change what Pulse emails you in Settings, Notifications.');
    }

    private static function oneLine(string $text): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', $text) ?? '');
    }

    /** One line of text that Markdown will leave exactly as written. */
    private static function literal(string $text): string
    {
        return preg_replace('/([\\\\`*_{}\[\]()<>#+!|~&])/', '\\\\$1', self::oneLine($text)) ?? '';
    }
}
