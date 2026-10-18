<?php

namespace App\Livewire\Pulse;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationBell extends Component
{
    #[Computed]
    public function unreadCount(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    #[Computed]
    public function recent(): DatabaseNotificationCollection
    {
        return auth()->user()->notifications()->latest()->limit(20)->get();
    }

    /**
     * resources/js/pulse-realtime.js dispatches this on a live
     * App\Notifications\NewFollower broadcast -- mirrors HomeFeed's own
     * #[On('post-created')] pattern.
     */
    #[On('notification-received')]
    public function refresh(): void
    {
        unset($this->unreadCount, $this->recent);
    }

    /**
     * One row of the bell: who/what (bold), the sentence, and where it
     * leads. Notification payloads are per-type, so the wording lives here
     * rather than assuming every notification is a new follower.
     *
     * @return array{lead: string, text: string, url: ?string}
     */
    public function present(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        return match ($data['type'] ?? 'new_follower') {
            'community_join_requested' => [
                'lead' => $data['requester_name'],
                'text' => ' asked to join '.$data['community_name'],
                'url' => route('communities.show', $data['community_slug']),
            ],
            'community_join_decision' => ($data['approved'] ?? false)
                ? [
                    'lead' => 'Your request to join '.$data['community_name'].' was approved',
                    'text' => '',
                    'url' => route('communities.show', $data['community_slug']),
                ]
                : [
                    'lead' => 'Your request to join '.$data['community_name'].' was not accepted',
                    'text' => '',
                    'url' => null,
                ],
            'report_outcome' => [
                'lead' => 'Your report was reviewed',
                'text' => ($data['actioned'] ?? false) ? ': the content was removed.' : ': no action was needed.',
                'url' => null,
            ],
            'content_removed' => [
                'lead' => 'A '.($data['content'] ?? 'post').' of yours was removed by a moderator',
                'text' => '',
                'url' => null,
            ],
            default => [
                'lead' => $data['follower_name'] ?? 'Someone',
                'text' => ' started following you',
                'url' => isset($data['follower_id']) ? route('users.show', $data['follower_id']) : null,
            ],
        };
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
        unset($this->unreadCount, $this->recent);
    }

    public function render(): View
    {
        return view('livewire.pulse.notification-bell');
    }
}
