<?php

namespace App\Livewire\Pulse;

use App\Livewire\Pulse\Me\Concerns\PresentsNotifications;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The shell now shows a badge link to Me, Notifications that the poller keeps current (layouts/app.blade.php); this
 * component renders the same kit link, with the unread count drawn on the server, for any page that still mounts it.
 * Wording comes from the shared presenter: Pulse notifications through NotificationPresenter, older ones as before.
 */
class NotificationBell extends Component
{
    use PresentsNotifications;

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
     * resources/js/pulse-realtime.js dispatches this on a live broadcast.
     */
    #[On('notification-received')]
    public function refresh(): void
    {
        unset($this->unreadCount, $this->recent);
    }

    /**
     * One row: who or what (bold), the sentence, and where it leads.
     *
     * @return array{lead: string, text: string, url: ?string}
     */
    public function present(DatabaseNotification $notification): array
    {
        return $this->presentNotification($notification);
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
