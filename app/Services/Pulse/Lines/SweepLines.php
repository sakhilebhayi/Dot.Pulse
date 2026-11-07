<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseConversation;

/**
 * Hourly housekeeping behind `pulse:lines:sweep`: expire stale quotes, close requests nobody answered in 14 days
 * (and tell the sender, never silently), lapse doors, and reveal reviews whose blind period is over.
 */
class SweepLines
{
    public function __construct(
        private readonly QuoteService $quotes,
        private readonly Doors $doors,
        private readonly ReviewService $reviews,
        private readonly LineService $lines,
    ) {}

    /** @return array{quotes: int, requests: int, doors: int, reviews: int} */
    public function run(): array
    {
        return [
            'quotes' => $this->quotes->expireStale(),
            'requests' => $this->expireRequests(),
            'doors' => $this->doors->lapse(),
            'reviews' => $this->reviews->revealDue(),
        ];
    }

    /** Requests untouched for 14 days close as expired. The sender is told in plain words. */
    public function expireRequests(): int
    {
        $days = (int) config('pulse.lines.request_expiry_days', 14);
        $count = 0;

        PulseConversation::query()
            ->where('status', PulseConversation::REQUESTED)
            ->where('created_at', '<=', now()->subDays($days))
            ->with(['initiator', 'recipient', 'entity', 'initiatorEntity'])
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use (&$count, $days) {
                foreach ($chunk as $c) {
                    $moved = PulseConversation::query()->whereKey($c->id)->where('status', PulseConversation::REQUESTED)->update([
                        'status' => PulseConversation::CLOSED,
                        'closed_reason' => 'expired',
                        'closed_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if (! $moved) {
                        continue;
                    }

                    $count++;
                    AuditLog::record(null, 'line.expire', $c, ['days' => $days]);

                    // The same words whether it was ignored or simply unseen: the sender is never told which.
                    $this->lines->notifySide($c, 'initiator', null, [
                        'type' => 'line_expired',
                        'title' => $this->lines->partyName($c, 'recipient').' did not reply in '.$days.' days',
                        'body' => 'Your request about '.($c->context_title ?: 'your enquiry').' has closed. You can ask again later.',
                    ], 'lines');
                }
            });

        return $count;
    }
}
