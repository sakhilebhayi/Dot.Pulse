<?php

namespace App\Livewire\Pulse\Me;

use App\Livewire\Pulse\Me\Concerns\PresentsNotifications;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/** "Me, Notifications": the list. Pulse notifications read in the Pulse wording, older ones in theirs. */
class NotificationList extends Component
{
    use PresentsNotifications;

    public int $limit = 20;

    public bool $unreadOnly = false;

    #[Computed]
    public function notifications()
    {
        $query = auth()->user()->notifications()->latest();

        if ($this->unreadOnly) {
            $query->whereNull('read_at');
        }

        return $query->limit($this->limit + 1)->get();
    }

    #[Computed]
    public function hasMore(): bool
    {
        return $this->notifications->count() > $this->limit;
    }

    #[Computed]
    public function unreadCount(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    public function more(): void
    {
        $this->limit += 20;
        unset($this->notifications, $this->hasMore);
    }

    public function markRead(string $id): void
    {
        $this->own($id)?->markAsRead();
        $this->forget();
    }

    public function markUnread(string $id): void
    {
        $this->own($id)?->markAsUnread();
        $this->forget();
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
        $this->forget();
    }

    /** Follow a notification: mark it read, then go where it points (same site only). */
    public function open(string $id): void
    {
        $n = $this->own($id);

        if (! $n) {
            return;
        }

        $n->markAsRead();
        $this->forget();
        $url = $this->safeUrl($this->presentNotification($n)['url']);

        if ($url) {
            $this->redirect($url);
        }
    }

    private function own(string $id): ?DatabaseNotification
    {
        return auth()->user()->notifications()->whereKey($id)->first();
    }

    private function forget(): void
    {
        unset($this->notifications, $this->hasMore, $this->unreadCount);
    }

    public function render(): View
    {
        return view('livewire.pulse.me.notification-list');
    }
}
