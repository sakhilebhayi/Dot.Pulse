<?php

namespace App\Services\Pulse\Discovery\Digest;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The digest email. Plain, one column, every item with its reason, a one-click unsubscribe in the footer and in
 * the List-Unsubscribe headers. Mail only: the in-app side of a digest is the Floor itself.
 */
class DigestNotification extends Notification
{
    /** @param array<string, mixed> $digest the array DigestBuilder::build() returns */
    public function __construct(public readonly array $digest) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $unsubscribe = (string) $this->digest['unsubscribe_url'];

        return (new MailMessage)
            ->subject($this->digest['subject'])
            ->view(['mail.pulse.digest', 'mail.pulse.digest-text'], ['digest' => $this->digest, 'name' => $notifiable->name ?? ''])
            ->withSymfonyMessage(function ($message) use ($unsubscribe) {
                $headers = $message->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', '<'.$unsubscribe.'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                $headers->addTextHeader('Precedence', 'bulk');
            });
    }
}
