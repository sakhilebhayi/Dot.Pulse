<?php

namespace App\Services\Pulse\Lines;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;

/**
 * Turns a stored Pulse notification into the three parts the bell shows: a bold lead, the rest of the sentence and
 * a link. App\Livewire\Pulse\NotificationBell keeps its own wording for the older community and follow types; call
 * this for anything whose data carries a `category` key (every notification written by Lines\Notifier).
 */
class NotificationPresenter
{
    public static function handles(array $data): bool
    {
        return isset($data['category']);
    }

    /** @return array{lead: string, text: string, url: ?string, category: string} */
    public static function present(DatabaseNotification|array $notification): array
    {
        $data = $notification instanceof DatabaseNotification ? $notification->data : $notification;
        $count = (int) ($data['count'] ?? 1);
        $url = $data['url'] ?? null;

        if (! $url && isset($data['route']['name']) && Route::has($data['route']['name'])) {
            $url = route($data['route']['name'], $data['route']['params'] ?? []);
        }

        $title = (string) ($data['title'] ?? 'Something happened on Pulse');
        if ($count > 1) {
            $title .= " ({$count})";
        }

        return [
            'lead' => $title,
            'text' => isset($data['body']) && $data['body'] !== '' ? ': '.$data['body'] : '',
            'url' => $url,
            'category' => (string) ($data['category'] ?? 'system'),
        ];
    }
}
