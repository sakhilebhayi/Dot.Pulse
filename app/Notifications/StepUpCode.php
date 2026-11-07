<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The one time code that confirms a sensitive privacy action (download or erase). Mail only, never stored in the database. */
class StepUpCode extends Notification
{
    public function __construct(private readonly string $code, private readonly string $action, private readonly int $minutes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $what = $this->action === 'erase' ? 'erase what Pulse holds about you' : 'download your Pulse data';

        return (new MailMessage)
            ->subject('Your Pulse confirmation code')
            ->line('Your code is '.$this->code.'.')
            ->line('Use it within '.$this->minutes.' minutes to '.$what.'. If this was not you, ignore this email and nothing happens.');
    }
}
