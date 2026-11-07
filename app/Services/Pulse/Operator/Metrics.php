<?php

namespace App\Services\Pulse\Operator;

use App\Models\PulseAuditEntry;
use App\Models\PulseBlock;
use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseEntityBlock;
use App\Models\PulseKnockBudget;
use App\Models\PulseMatch;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseProfile;
use App\Models\PulseReport;
use App\Models\PulseReview;
use App\Models\PulseRole;
use App\Services\Pulse\Lines\KnockBudget;
use App\Services\Pulse\Lines\ResponseStats;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The operator's numbers (D15). Every figure is counted or measured from real rows, over a date range that is
 * stated next to it, and every metric carries its definition in one line.
 *
 * Honesty rules:
 *  - A metric whose source has never held a row is `null`, shown as "No data yet". It is never a zero dressed as
 *    a result. Once the source holds rows a zero is a real count and is shown as 0.
 *  - A rate or a median with nothing to divide or order is `null`, never 0.
 *  - Point-in-time metrics (people, live listings, the queue, the knock budget) say "now"; the rest cover the range.
 *  - Nothing is invented: there is no funnel, no estimate, no projection. Only both-confirmed outcomes count as results.
 *
 * Pure counting queries: no names, emails or row data leave this class. The same counts feed the Dot.Analytics
 * publisher (see publishable()).
 */
class Metrics
{
    /** Longest range the operator can ask for, in days. */
    public const MAX_DAYS = 365;

    public function __construct(private readonly KnockBudget $budget) {}

    /**
     * The [from, to] window for the last $days days up to $now, in the app timezone (how rows are stored).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function window(int $days, ?CarbonInterface $now = null): array
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        $to = Carbon::instance($now ?? now())->setTimezone((string) config('app.timezone', 'UTC'));

        return [$to->copy()->subDays($days), $to];
    }

    // ---------------------------------------------------------------- people and listings (now)

    /**
     * People who finished onboarding, by kind and by how discoverable they chose to be.
     *
     * @return array{total: ?int, by_kind: array<string, int>, by_discoverability: array<string, int>}
     */
    public function people(): array
    {
        if (! $this->hasRows('pulse_profiles')) {
            return ['total' => null, 'by_kind' => [], 'by_discoverability' => []];
        }

        $base = fn () => PulseProfile::query()->whereNotNull('onboarded_at');

        return [
            'total' => $base()->count(),
            'by_kind' => $this->grouped($base(), 'kind', ['individual', 'professional']),
            'by_discoverability' => $this->grouped($base(), 'discoverability', PulseProfile::DISCOVERABILITY),
        ];
    }

    /** Business pages that are live and approved by the rules or by a person. */
    public function activeEntities(): ?int
    {
        return $this->hasRows('pulse_entities')
            ? PulseEntity::query()->where('status', 'live')->where('moderation', 'approved')->count()
            : null;
    }

    /**
     * Offers, roles and needs that are live and approved right now, split by kind.
     *
     * @return array{offers: array{total: ?int, by_kind: array<string, int>}, roles: array{total: ?int, by_kind: array<string, int>}, needs: array{total: ?int, by_kind: array<string, int>}}
     */
    public function liveListings(): array
    {
        // A listing of a page that is suspended, archived, held or removed is not live, whatever its own status says.
        $liveEntity = fn ($q) => $q->where('status', 'live')->where('moderation', 'approved')->whereNull('deleted_at');
        $offers = PulseOffer::query()->where('status', 'live')->where('moderation', 'approved')->whereHas('entity', $liveEntity);
        $roles = PulseRole::query()->where('status', 'live')->where('moderation', 'approved')->whereHas('entity', $liveEntity);
        $needs = PulseNeed::query()->where('status', 'open')->where('moderation', 'approved');

        return [
            'offers' => $this->listingBlock('pulse_offers', $offers, 'kind', ['product', 'service']),
            'roles' => $this->listingBlock('pulse_roles', $roles, 'employment_type', ['full_time', 'part_time', 'contract', 'internship', 'freelance']),
            'needs' => $this->listingBlock('pulse_needs', $needs, 'kind', ['need', 'supplier', 'collab', 'investor', 'mentor', 'intro']),
        ];
    }

    // ---------------------------------------------------------------- the floor and the lines (range)

    /**
     * Matches shown to someone on the Floor, counted when first seen in the range.
     *
     * @return array{seen: ?int, people: ?int}
     */
    public function floor(CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $this->hasRows('pulse_matches')) {
            return ['seen' => null, 'people' => null];
        }

        $q = fn () => PulseMatch::query()->whereBetween('seen_at', [$from, $to]);

        return ['seen' => $q()->count(), 'people' => (int) $q()->distinct()->count('user_id')];
    }

    /** First contacts with a request card sent in the range (a direct message to someone who takes them is not a knock). */
    public function knocks(CarbonInterface $from, CarbonInterface $to): ?int
    {
        if (! $this->hasRows('pulse_conversations')) {
            return null;
        }

        return $this->knockQuery($from, $to)->count();
    }

    /**
     * How the knocks sent in the range ended up. The groups do not have to add up to the total: a request that was
     * withdrawn, marked as spam or opened straight away belongs to none of them.
     *
     * @return array{sent: ?int, accepted: ?int, declined: ?int, lapsed: ?int, waiting: ?int}
     */
    public function requests(CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $this->hasRows('pulse_conversations')) {
            return ['sent' => null, 'accepted' => null, 'declined' => null, 'lapsed' => null, 'waiting' => null];
        }

        $q = fn () => $this->knockQuery($from, $to);

        return [
            'sent' => $q()->count(),
            'accepted' => $q()->whereNotNull('first_response_at')
                ->whereNotIn('status', [PulseConversation::DECLINED, PulseConversation::SPAM])->count(),
            'declined' => $q()->where('status', PulseConversation::DECLINED)->count(),
            'lapsed' => $q()->where('status', PulseConversation::CLOSED)->where('closed_reason', 'expired')->count(),
            'waiting' => $q()->where('status', PulseConversation::REQUESTED)->count(),
        ];
    }

    /**
     * How often, and how fast, lines started in the range get answered. Same definition as the page-level figure
     * (ResponseStats): a line counts once it is answered or 48 hours old; spam is left out; the median is the
     * middle time from the line starting to the first answer.
     *
     * @return array{received: ?int, answered: ?int, rate: ?float, median_seconds: ?int}
     */
    public function responses(CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $this->hasRows('pulse_conversations')) {
            return ['received' => null, 'answered' => null, 'rate' => null, 'median_seconds' => null];
        }

        $rows = PulseConversation::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('status', '!=', PulseConversation::SPAM)
            ->whereIn('kind', PulseConversation::KINDS)
            ->whereNotNull('card')
            ->limit(20000)
            ->get(['id', 'created_at', 'first_response_at']);

        $cutoff = Carbon::instance($to)->subHours(ResponseStats::GRACE_HOURS);
        $counted = $rows->filter(fn ($c) => $c->first_response_at !== null || $c->created_at->lte($cutoff));
        $answered = $counted->filter(fn ($c) => $c->first_response_at !== null);

        $seconds = $answered
            ->map(fn ($c) => max(0, $c->created_at->diffInSeconds($c->first_response_at, false)))
            ->sort()->values();

        return [
            'received' => $counted->count(),
            'answered' => $answered->count(),
            'rate' => $counted->isEmpty() ? null : round($answered->count() / $counted->count(), 3),
            'median_seconds' => $seconds->isEmpty() ? null : self::median($seconds),
        ];
    }

    /**
     * Results both sides confirmed in the range, by outcome. A proposed outcome the other side has not confirmed is
     * not counted, and neither is a confirmed "not this time".
     *
     * @return array{total: ?int, by_outcome: array<string, int>}
     */
    public function outcomes(CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $this->hasRows('pulse_conversations')) {
            return ['total' => null, 'by_outcome' => []];
        }

        // The moment both sides had confirmed is the later of the two stamps.
        $confirmedAt = 'CASE WHEN outcome_initiator_at > outcome_recipient_at THEN outcome_initiator_at ELSE outcome_recipient_at END';

        $q = PulseConversation::query()->successful()->whereRaw("{$confirmedAt} BETWEEN ? AND ?", [$this->stamp($from), $this->stamp($to)]);

        $by = $this->grouped($q, 'outcome', PulseConversation::POSITIVE_OUTCOMES);

        return ['total' => array_sum($by), 'by_outcome' => $by];
    }

    /** Reviews that became public in the range (both sides written, or 14 days passed) and were not removed. */
    public function reviewsRevealed(CarbonInterface $from, CarbonInterface $to): ?int
    {
        if (! $this->hasRows('pulse_reviews')) {
            return null;
        }

        return PulseReview::query()->where('moderation', 'approved')->whereBetween('revealed_at', [$from, $to])->count();
    }

    // ---------------------------------------------------------------- safety (range and now)

    /** @return array{opened: ?int, resolved: ?int, dismissed: ?int} */
    public function reports(CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $this->hasRows('pulse_reports')) {
            return ['opened' => null, 'resolved' => null, 'dismissed' => null];
        }

        return [
            'opened' => PulseReport::query()->whereBetween('created_at', [$from, $to])->count(),
            'resolved' => PulseReport::query()->where('status', PulseReport::RESOLVED)->whereBetween('resolved_at', [$from, $to])->count(),
            'dismissed' => PulseReport::query()->where('status', PulseReport::DISMISSED)->whereBetween('resolved_at', [$from, $to])->count(),
        ];
    }

    /**
     * What is waiting for a person right now: held posts, held listings, open reports, held reviews. The age is how
     * long the oldest and the middle item have waited.
     *
     * @return array{waiting: int, oldest_seconds: ?int, median_seconds: ?int}
     */
    public function queue(?CarbonInterface $now = null): array
    {
        $now = Carbon::instance($now ?? now());
        $since = app(ModerationDesk::class)->waitingSince();

        if ($since->isEmpty()) {
            return ['waiting' => 0, 'oldest_seconds' => null, 'median_seconds' => null];
        }

        $ages = $since->map(fn (CarbonInterface $at) => max(0, $at->diffInSeconds($now, false)))->sort()->values();

        return ['waiting' => $ages->count(), 'oldest_seconds' => (int) $ages->last(), 'median_seconds' => self::median($ages)];
    }

    /** Blocks made in the range, between people and from a person to a page. */
    public function blocks(CarbonInterface $from, CarbonInterface $to): ?int
    {
        if (! $this->hasRows('pulse_blocks') && ! $this->hasRows('pulse_entity_blocks')) {
            return null;
        }

        return PulseBlock::query()->whereBetween('created_at', [$from, $to])->count()
            + PulseEntityBlock::query()->whereBetween('created_at', [$from, $to])->count();
    }

    /** Digest emails sent in the range (one per person per slot, only ever when it held something real). */
    public function digestsSent(CarbonInterface $from, CarbonInterface $to): ?int
    {
        return $this->auditCount('pulse.digest.sent', $from, $to);
    }

    /**
     * People who have no first contacts left in their current week. Counted now, from the real budget rows.
     */
    public function knockBudgetExhausted(?CarbonInterface $now = null): ?int
    {
        if (! $this->hasRows('pulse_knock_budgets')) {
            return null;
        }

        $now = Carbon::instance($now ?? now());
        $windowDays = max(1, (int) config('pulse.lines.knock_budget.window_days', 7));

        $count = 0;

        PulseKnockBudget::query()
            ->where('spent', '>', 0)
            ->where('window_started_at', '>', $now->copy()->subDays($windowDays))
            ->with('user:id,created_at')
            ->chunkById(500, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    if ($row->user === null) {
                        continue;
                    }
                    $allowance = max(0, $this->budget->base($row->user) - $row->penalty) + $row->bonus;
                    if ($row->spent >= $allowance) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    // ---------------------------------------------------------------- series

    /**
     * Knocks sent per day over the range, every day present, so a quiet day is a real zero and not a gap.
     *
     * @return list<array{date: string, value: int}>
     */
    public function knocksPerDay(CarbonInterface $from, CarbonInterface $to): array
    {
        $counts = $this->knockQuery($from, $to)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as n')
            ->groupBy('d')
            ->pluck('n', 'd');

        $out = [];
        for ($day = Carbon::instance($from)->startOfDay(); $day->lte($to); $day->addDay()) {
            $key = $day->format('Y-m-d');
            $out[] = ['date' => $key, 'value' => (int) ($counts[$key] ?? 0)];
        }

        return $out;
    }

    // ---------------------------------------------------------------- the whole picture

    /**
     * Everything the dashboard shows, with the range and a one-line definition per metric.
     *
     * @return array<string, mixed>
     */
    public function snapshot(int $days = 30, ?CarbonInterface $now = null): array
    {
        [$from, $to] = $this->window($days, $now);

        $people = $this->people();
        $listings = $this->liveListings();
        $floor = $this->floor($from, $to);
        $requests = $this->requests($from, $to);
        $responses = $this->responses($from, $to);
        $outcomes = $this->outcomes($from, $to);
        $reports = $this->reports($from, $to);
        $queue = $this->queue($to);

        return [
            'range' => ['from' => $from, 'to' => $to, 'days' => max(1, min(self::MAX_DAYS, $days))],
            'people' => $people,
            'entities' => $this->activeEntities(),
            'listings' => $listings,
            'floor' => $floor,
            'knocks' => $requests['sent'],
            'requests' => $requests,
            'responses' => $responses,
            'outcomes' => $outcomes,
            'reviews' => $this->reviewsRevealed($from, $to),
            'reports' => $reports,
            'queue' => $queue,
            'digests' => $this->digestsSent($from, $to),
            'exhausted' => $this->knockBudgetExhausted($to),
            'blocks' => $this->blocks($from, $to),
            'series' => ['knocks_per_day' => $this->knocksPerDay($from, $to)],
        ];
    }

    /**
     * The whole-number aggregates the Dot.Analytics publisher adds to the daily snapshot. Counts only (Analytics
     * takes whole numbers), `null` where there is no data, so "not reported" is never sent as zero. Keys that
     * Dot.Analytics' catalogue does not list yet are dropped by it, which is harmless.
     *
     * @return array<string, int|null>
     */
    public function publishable(CarbonInterface $asOf, int $days = 7): array
    {
        [$from, $to] = $this->window($days, $asOf);

        $people = $this->people();
        $listings = $this->liveListings();
        $requests = $this->requests($from, $to);
        $responses = $this->responses($from, $to);
        $outcomes = $this->outcomes($from, $to);
        $reports = $this->reports($from, $to);
        $queue = $this->queue($to);
        $suffix = "_{$days}d";

        return [
            'people_individual' => $people['total'] === null ? null : ($people['by_kind']['individual'] ?? 0),
            'people_professional' => $people['total'] === null ? null : ($people['by_kind']['professional'] ?? 0),
            'people_discoverable' => $people['total'] === null ? null : ($people['by_discoverability']['open'] ?? 0) + ($people['by_discoverability']['anonymous'] ?? 0),
            'entities_live' => $this->activeEntities(),
            'offers_live' => $listings['offers']['total'],
            'roles_live' => $listings['roles']['total'],
            'needs_live' => $listings['needs']['total'],
            'matches_seen'.$suffix => $this->floor($from, $to)['seen'],
            'knocks'.$suffix => $requests['sent'],
            'requests_accepted'.$suffix => $requests['accepted'],
            'requests_declined'.$suffix => $requests['declined'],
            'requests_lapsed'.$suffix => $requests['lapsed'],
            'lines_received'.$suffix => $responses['received'],
            'lines_answered'.$suffix => $responses['answered'],
            'median_response_seconds'.$suffix => $responses['median_seconds'],
            'outcomes_won'.$suffix => $outcomes['total'] === null ? null : ($outcomes['by_outcome']['won'] ?? 0),
            'outcomes_hired'.$suffix => $outcomes['total'] === null ? null : ($outcomes['by_outcome']['hired'] ?? 0),
            'outcomes_partnered'.$suffix => $outcomes['total'] === null ? null : ($outcomes['by_outcome']['partnered'] ?? 0),
            'reviews_revealed'.$suffix => $this->reviewsRevealed($from, $to),
            'reports_opened'.$suffix => $reports['opened'],
            'reports_resolved'.$suffix => $reports['resolved'],
            'queue_waiting' => $queue['waiting'],
            'queue_oldest_hours' => $queue['oldest_seconds'] === null ? null : intdiv($queue['oldest_seconds'], 3600),
            'digests_sent'.$suffix => $this->digestsSent($from, $to),
            'knock_budget_exhausted' => $this->knockBudgetExhausted($to),
            'blocks'.$suffix => $this->blocks($from, $to),
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function knockQuery(CarbonInterface $from, CarbonInterface $to)
    {
        return PulseConversation::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('initiator_id')
            ->whereNotNull('card');
    }

    private function auditCount(string $action, CarbonInterface $from, CarbonInterface $to): ?int
    {
        if (! $this->hasRows('pulse_audit_log')) {
            return null;
        }

        return PulseAuditEntry::query()->where('action', $action)->whereBetween('created_at', [$from, $to])->count();
    }

    /**
     * @param  list<string>  $known  keys always present (0 when absent) once the table holds rows
     * @return array<string, int>
     */
    private function grouped($query, string $column, array $known): array
    {
        $counts = $query->select($column, DB::raw('COUNT(*) as n'))->groupBy($column)->pluck('n', $column)->map(fn ($n) => (int) $n)->all();

        $out = [];
        foreach ($known as $key) {
            $out[$key] = $counts[$key] ?? 0;
        }
        foreach ($counts as $key => $n) {
            $out[$key] ??= $n;
        }

        return $out;
    }

    /** @return array{total: ?int, by_kind: array<string, int>} */
    private function listingBlock(string $table, $query, string $column, array $known): array
    {
        if (! $this->hasRows($table)) {
            return ['total' => null, 'by_kind' => []];
        }

        $by = $this->grouped(clone $query, $column, $known);

        return ['total' => array_sum($by), 'by_kind' => $by];
    }

    private function hasRows(string $table): bool
    {
        return DB::table($table)->limit(1)->exists();
    }

    private function stamp(CarbonInterface $moment): string
    {
        return Carbon::instance($moment)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    /** @param  Collection<int, int|float>  $sorted */
    private static function median(Collection $sorted): int
    {
        $n = $sorted->count();
        $mid = intdiv($n, 2);

        return (int) ($n % 2 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2);
    }
}
