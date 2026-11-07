<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ring check (research section 6: "anomaly checks on review rings"). Two accounts, or two pages, that keep confirming
 * each other's results inflate counts and reviews. The check uses only what Pulse really has: how often the same two
 * parties confirmed a result in the window, and how old their accounts are. It never blocks anything. The result is
 * accepted and the people see it as confirmed; it is marked `needs_review` and stays out of public metrics, the ladder
 * and badges until a moderator approves or discards it (ModerationDesk::decideResult). Reviews written on it are held.
 *
 * A "party" is the page when a side is a page, otherwise the person. The pair is unordered, so A with B and B with A
 * are the same pair. Results a moderator already cleared no longer count towards the limit, so a real supplier and a
 * real regular customer are asked about once, not for ever.
 */
class RingCheck
{
    public const FREQUENCY = 'pair_frequency';

    public const NEW_ACCOUNT = 'new_account_pair';

    /** Judge a result both sides just confirmed. Returns the reason it was flagged, or null. */
    public function assess(PulseConversation $c): ?string
    {
        $c->refresh();

        if (! $c->hasConfirmedOutcome() || ! in_array($c->outcome, PulseConversation::POSITIVE_OUTCOMES, true) || $c->needs_review) {
            return $c->needs_review ? (string) $c->needs_review_reason : null;
        }

        [$a, $b] = $this->parties($c);

        if ($a === null || $b === null) {
            return null;
        }

        $count = $this->pairResults($a, $b, $c->id) + 1; // this one included

        $newAccount = $this->hasNewAccount($c);
        $limit = $newAccount
            ? (int) config('pulse.ring.new_account_max_pair_results', 1)
            : (int) config('pulse.ring.max_pair_results', 3);

        if ($count <= $limit) {
            return null;
        }

        $reason = $newAccount && $count <= (int) config('pulse.ring.max_pair_results', 3) ? self::NEW_ACCOUNT : self::FREQUENCY;

        $c->forceFill(['needs_review' => true, 'needs_review_reason' => $reason, 'needs_review_at' => now()])->save();
        AuditLog::record(null, 'line.ring_flag', $c, ['reason' => $reason, 'pair_results' => $count]);

        return $reason;
    }

    /** How many confirmed results (not counting this line) the pair has in the window that no moderator has cleared. */
    public function pairResults(string $a, string $b, int $exceptId = 0): int
    {
        $q = $this->resultsQuery()->where('id', '!=', $exceptId)->whereNull('review_cleared_at');
        $this->wherePair($q, $a, $b);

        return $q->count();
    }

    /** The party keys of a line, "e12" for a page and "u7" for a person; null when a side is not known. @return array{0: ?string, 1: ?string} */
    public function parties(PulseConversation $c): array
    {
        $first = $c->initiator_entity_id ? 'e'.$c->initiator_entity_id : ($c->initiator_id ? 'u'.$c->initiator_id : null);
        $second = $c->entity_id ? 'e'.$c->entity_id : ($c->recipient_id ? 'u'.$c->recipient_id : null);

        return [$first, $second];
    }

    /** True when any account taking part is younger than the limit. */
    public function hasNewAccount(PulseConversation $c): bool
    {
        $days = max(1, (int) config('pulse.ring.new_account_days', 7));
        $cutoff = now()->subDays($days);

        $ids = array_filter([$c->initiator_id, $c->recipient_id]);

        if ($c->entity_id || $c->initiator_entity_id) {
            $creators = PulseEntity::query()->whereIn('id', array_filter([$c->entity_id, $c->initiator_entity_id]))->pluck('created_by');
            $ids = array_merge($ids, $creators->filter()->all());
        }

        return $ids !== [] && User::query()->whereIn('id', array_unique($ids))->where('created_at', '>', $cutoff)->exists();
    }

    /** Confirmed results inside the window, as a query builder over the conversations table. */
    private function resultsQuery(): \Illuminate\Database\Query\Builder
    {
        $since = now()->subDays(max(1, (int) config('pulse.ring.window_days', 90)));

        return DB::table('pulse_conversations')
            ->where('outcome', '!=', 'none')->whereIn('outcome', PulseConversation::POSITIVE_OUTCOMES)
            ->whereNotNull('outcome_initiator_at')->whereNotNull('outcome_recipient_at')
            ->whereRaw('CASE WHEN outcome_initiator_at > outcome_recipient_at THEN outcome_initiator_at ELSE outcome_recipient_at END >= ?', [$since]);
    }

    private function wherePair($q, string $a, string $b): void
    {
        $sideA = fn ($w, string $key, string $entityCol, string $userCol) => str_starts_with($key, 'e')
            ? $w->where($entityCol, (int) substr($key, 1))
            : $w->where($userCol, (int) substr($key, 1))->whereNull($entityCol);

        $q->where(function ($w) use ($a, $b, $sideA) {
            $w->where(function ($x) use ($a, $b, $sideA) {
                $sideA($x, $a, 'initiator_entity_id', 'initiator_id');
                $x->where(fn ($y) => $sideA($y, $b, 'entity_id', 'recipient_id'));
            })->orWhere(function ($x) use ($a, $b, $sideA) {
                $sideA($x, $b, 'initiator_entity_id', 'initiator_id');
                $x->where(fn ($y) => $sideA($y, $a, 'entity_id', 'recipient_id'));
            });
        });
    }
}
