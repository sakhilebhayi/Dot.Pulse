<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\User;

/**
 * Close the loop in numbers (D21): how often, and how fast, a page or a person answers what is sent to them.
 * Computed from real rows only. A line counts as received when it is no longer fresh (older than 48 hours) or was
 * answered; answered means accepted, replied to or declined. Quietly ignored and expired requests count as not
 * answered. Spam is left out. With nothing received the rate is null, never a made up number.
 */
class ResponseStats
{
    public const WINDOW_DAYS = 90;

    public const GRACE_HOURS = 48;

    /**
     * @return array{received: int, answered: int, response_rate: ?float, median_first_response_seconds: ?int, window_days: int}
     */
    public static function for(PulseEntity|User $subject): array
    {
        $rows = PulseConversation::query()
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->where('status', '!=', PulseConversation::SPAM)
            ->whereIn('kind', ['dm', 'enquiry', 'quote', 'application', 'intro', 'collab', 'invite'])
            ->when($subject instanceof PulseEntity,
                fn ($q) => $q->where('entity_id', $subject->id),
                fn ($q) => $q->where('recipient_id', $subject->id)->whereNull('entity_id'))
            ->limit(2000)
            ->get(['id', 'status', 'created_at', 'first_response_at']);

        $cutoff = now()->subHours(self::GRACE_HOURS);
        $counted = $rows->filter(fn ($c) => $c->first_response_at !== null || $c->created_at->lte($cutoff));
        $answered = $counted->filter(fn ($c) => $c->first_response_at !== null);

        $received = $counted->count();
        $seconds = $answered->map(fn ($c) => max(0, $c->created_at->diffInSeconds($c->first_response_at, false)))->sort()->values();

        return [
            'received' => $received,
            'answered' => $answered->count(),
            'response_rate' => $received > 0 ? round($answered->count() / $received, 3) : null,
            'median_first_response_seconds' => $seconds->isEmpty() ? null : self::median($seconds),
            'window_days' => self::WINDOW_DAYS,
        ];
    }

    private static function median($sorted): int
    {
        $n = $sorted->count();
        $mid = intdiv($n, 2);

        return (int) ($n % 2 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2);
    }
}
