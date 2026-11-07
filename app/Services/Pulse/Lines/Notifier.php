<?php

namespace App\Services\Pulse\Lines;

use App\Models\User;
use App\Notifications\PulseNotice;
use Illuminate\Notifications\DatabaseNotification;
use InvalidArgumentException;
use Throwable;

/**
 * The one door every Pulse notification goes through. Transactional categories (lines, outcomes, system) always
 * get an in app notification; matches and digest need the person's in app preference. Email is sent only when the
 * preferences allow it, the person is not in quiet hours, and email is set to instant (system mail ignores the
 * frequency). A payload with a `coalesce_key` folds into the person's unread notification with the same key.
 * Pulse never sends marketing from a business to a person: nothing here accepts a sender other than the platform.
 */
class Notifier
{
    public function __construct(private readonly NotificationPrefs $prefs) {}

    /**
     * @param  array<string, mixed>  $payload  type, title, body (optional), route {name, params} (optional), coalesce_key (optional)
     */
    public function notify(User $u, string $category, array $payload): void
    {
        if (! in_array($category, NotificationPrefs::CATEGORIES, true)) {
            throw new InvalidArgumentException("Unknown notification category [{$category}].");
        }

        $payload['category'] = $category;
        $payload['type'] ??= $category;
        $payload['title'] ??= '';

        if (isset($payload['route']['name']) && \Illuminate\Support\Facades\Route::has($payload['route']['name'])) {
            $payload['url'] = route($payload['route']['name'], $payload['route']['params'] ?? []);
        }

        $transactional = in_array($category, NotificationPrefs::TRANSACTIONAL, true);

        if (! $transactional && ! $this->prefs->allowed($u, $category, 'in_app')) {
            return;
        }

        if (! $this->coalesce($u, $payload)) {
            $u->notifyNow(new PulseNotice($payload, ['database']));
        } else {
            return; // folded into an unread one: no second email either
        }

        if ($category === 'digest' || ! $this->prefs->allowed($u, $category, 'email') || $this->prefs->quietNow($u)) {
            return;
        }

        $frequency = $this->prefs->frequency($u);
        if ($category !== 'system' && $frequency !== 'instant') {
            return; // daily and weekly people get this in their digest
        }
        if ($frequency === 'off' && $category !== 'system') {
            return;
        }

        try {
            $u->notify(new PulseNotice($payload, ['mail']));
        } catch (Throwable $e) {
            report($e); // the in app copy is saved; a mail failure must not undo the action
        }
    }

    /** @return bool true when folded into an existing unread notification */
    private function coalesce(User $u, array $payload): bool
    {
        $key = $payload['coalesce_key'] ?? null;

        if ($key === null) {
            return false;
        }

        $existing = DatabaseNotification::query()
            ->where('notifiable_type', $u->getMorphClass())
            ->where('notifiable_id', $u->id)
            ->where('type', PulseNotice::class)
            ->whereNull('read_at')
            ->where('data', 'like', '%"coalesce_key":'.json_encode($key).'%')
            ->latest()
            ->first();

        if ($existing === null) {
            return false;
        }

        $data = $existing->data;
        $payload['count'] = (int) ($data['count'] ?? 1) + 1;
        $existing->forceFill(['data' => $payload])->save();
        $existing->touch();

        return true;
    }
}
