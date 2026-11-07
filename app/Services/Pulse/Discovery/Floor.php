<?php

namespace App\Services\Pulse\Discovery;

use App\Models\PulseMatch;
use App\Models\PulseMute;
use App\Models\PulseSave;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\Support\Audit;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Floor: a finite round of at most twenty beats built from a person's fresh matches. No endless feed.
 *
 *  - Exclusions: held, removed, expired and paused signals; people the viewer blocked or who blocked them; muted
 *    topics, businesses and people; things they dismissed or saved; their own; talent they may not see (not an
 *    employer, not discoverable, age not confirmed).
 *  - Diversity: never more than three beats in a row of one kind or from one business. The rule gives way only when
 *    nothing else is left to put between them (a pool of one kind), and then only for that tail.
 *  - Modes: trade (offers, needs, businesses, trade collabs), work (roles, talent, other collabs), local (anything in
 *    your city or region), learn (asks, events, news).
 *  - Paging: the cursor is a position in the ordered pool. A round is finished when `next` is null; then
 *    `caught_up` is true and `next_round_at` says when matches are next recomputed. Beats stay "new" until
 *    markSeen() is called with the ones the person actually saw.
 */
class Floor
{
    public const ROUND_SIZE = 20;

    public const RUN_LIMIT = 3;

    public const POOL = 200;

    public const MODES = ['all', 'trade', 'work', 'local', 'learn'];

    /** Reasons a person can give when dismissing. */
    public const DISMISS_REASONS = ['not_for_me', 'seen_it', 'wrong_place', 'not_now'];

    public function __construct(
        private readonly ContextLoader $contexts,
        private readonly Matcher $matcher,
        private readonly BeatBuilder $beats,
    ) {}

    /**
     * @return array{beats: list<Beat>, next: ?string, caught_up: bool, next_round_at: ?Carbon, counts: array{new: int}}
     */
    public function round(User $u, string $mode = 'all', ?string $cursor = null): array
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'all';
        $offset = $this->decode($cursor);

        $pool = $this->pool($u, $mode);
        $total = $pool['items']->count();
        $page = $pool['items']->slice($offset, self::ROUND_SIZE)->values();

        $beats = $this->beats->build($u->id, $page->map(fn ($i) => $i['signal']), $pool['pack'], $pool['matches'], [])->all();

        $next = ($offset + self::ROUND_SIZE) < $total ? $this->encode($offset + self::ROUND_SIZE) : null;
        $caught = $next === null;

        return [
            'beats' => $beats,
            'next' => $next,
            'caught_up' => $caught,
            'next_round_at' => $caught ? $this->nextRoundAt() : null,
            'counts' => ['new' => $total],
        ];
    }

    /** Record that the person saw these signals so they leave the next round. */
    public function markSeen(User $u, array $signalIds): void
    {
        if ($signalIds === []) {
            return;
        }

        DB::table('pulse_matches')->where('user_id', $u->id)->whereIn('signal_id', array_map('intval', $signalIds))
            ->whereNull('seen_at')->update(['seen_at' => now()]);
    }

    public function dismiss(User $u, PulseSignal $s, ?string $reason = null): void
    {
        $reason = in_array($reason, self::DISMISS_REASONS, true) ? $reason : null;

        $count = DB::table('pulse_matches')->where('user_id', $u->id)->where('signal_id', $s->id)->whereNull('dismissed_at')
            ->update(['dismissed_at' => now(), 'dismissed_reason' => $reason]);

        if ($count > 0) {
            Audit::record($u, 'pulse.floor.dismiss', $s, ['reason' => $reason]);
        }
    }

    /** @throws AuthorizationException when the person may not see this signal at all */
    public function saveForLater(User $u, PulseSignal $s): void
    {
        $this->assertVisible($u, $s);

        $save = PulseSave::firstOrCreate(['user_id' => $u->id, 'saveable_type' => 'signal', 'saveable_id' => $s->id]);

        if ($save->wasRecentlyCreated) {
            Audit::record($u, 'pulse.floor.save', $s);
        }
    }

    public function unsave(User $u, PulseSignal $s): void
    {
        if (PulseSave::where('user_id', $u->id)->where('saveable_type', 'signal')->where('saveable_id', $s->id)->delete() > 0) {
            Audit::record($u, 'pulse.floor.unsave', $s);
        }
    }

    /**
     * The person's saved signals, newest save first, as Beats. Things that are no longer visible to them (removed,
     * held, expired, blocked) are left out, not shown greyed.
     *
     * @return Collection<int, Beat>
     */
    public function savedList(User $u, int $limit = 100): Collection
    {
        $saves = PulseSave::where('user_id', $u->id)->where('saveable_type', 'signal')->latest('id')->limit($limit)->get(['saveable_id']);
        $ids = $saves->pluck('saveable_id')->all();

        if ($ids === []) {
            return collect();
        }

        $signals = PulseSignal::whereIn('id', $ids)->get()->keyBy('id');
        $ordered = collect($ids)->map(fn ($id) => $signals->get($id))->filter();

        $pack = SignalPack::build($ordered);
        $ctx = $this->contexts->loadExclusions($u->id);
        $visible = $ordered->filter(fn ($s) => $this->matcher->eligible($ctx, $s, $pack))->values();

        $matches = $this->storedMatches($u->id, $visible->pluck('id')->all());
        $saved = array_fill_keys($visible->pluck('id')->all(), true);

        return $this->beats->build($u->id, $visible, $pack, $matches, $saved);
    }

    /**
     * The person tapped a node on the sync meter: switch that input off for them. Topics, places, businesses and
     * people become mutes; "remote" becomes an input switch. Reasons that are not inputs (fresh, responsive,
     * a shared community) cannot be removed.
     *
     * @throws AuthorizationException when the match is not theirs
     * @throws \InvalidArgumentException when the reason is not something the person controls
     */
    public function removeReason(User $u, PulseMatch $m, string $code): void
    {
        if ((int) $m->user_id !== $u->id) {
            throw new AuthorizationException('That match is not yours.');
        }

        $reason = collect((array) $m->reasons)->first(fn ($r) => is_array($r) && ($r['code'] ?? null) === $code);

        if ($reason === null) {
            throw new \InvalidArgumentException("This match has no '{$code}' reason.");
        }

        $ref = isset($reason['ref']) ? (string) $reason['ref'] : null;

        [$kind, $target] = match (true) {
            in_array($code, ['need_offer', 'offer_need', 'skill_role', 'role_talent', 'topic'], true) && $ref !== null => ['topic', $ref],
            in_array($code, ['place_city', 'place_region', 'place_country'], true) && $ref !== null => ['place', $ref],
            $code === 'follows' && $ref !== null && str_starts_with($ref, 'user:') => ['user', substr($ref, 5)],
            $code === 'follows' && $ref !== null => ['entity', $ref],
            $code === 'remote' => ['input', 'remote'],
            default => throw new \InvalidArgumentException("The '{$code}' reason is not something you can switch off."),
        };

        PulseMute::firstOrCreate(['user_id' => $u->id, 'kind' => $kind, 'target' => $target]);
        Audit::record($u, 'pulse.floor.reason_removed', $m->signal ?? null, ['code' => $code, 'mute' => "{$kind}:{$target}"]);

        // Re-judge this one match with the new mute so the Floor changes at once.
        $signal = PulseSignal::find($m->signal_id);
        $fresh = $signal ? $this->matcher->forPair($u, $signal) : null;

        if ($fresh === null) {
            $m->forceFill(['dismissed_at' => now(), 'dismissed_reason' => 'muted'])->save();
        } else {
            $m->forceFill(['score' => $fresh['score'], 'reasons' => $fresh['reasons'], 'computed_at' => now()])->save();
        }
    }

    // -- the pool -----------------------------------------------------------------------------------------------

    /**
     * Every eligible unseen match for the mode, diversified, best first.
     *
     * @return array{items: Collection<int, array{signal: PulseSignal, id: int}>, pack: SignalPack, matches: array<int, array<string, mixed>>}
     */
    private function pool(User $u, string $mode): array
    {
        $kinds = Taxonomy::kindsOfMode($mode);

        $rows = PulseSignal::query()
            ->join('pulse_matches as m', 'm.signal_id', '=', 'pulse_signals.id')
            ->where('m.user_id', $u->id)
            ->whereNull('m.dismissed_at')
            ->whereNull('m.seen_at')
            ->where('pulse_signals.status', 'live')
            ->where(fn ($w) => $w->whereNull('pulse_signals.expires_at')->orWhere('pulse_signals.expires_at', '>', now()))
            ->whereNotIn('pulse_signals.id', fn ($q) => $q->select('saveable_id')->from('pulse_saves')->where('user_id', $u->id)->where('saveable_type', 'signal'))
            ->when($kinds !== null, fn ($q) => $q->whereIn('pulse_signals.kind', $kinds))
            ->orderByDesc('m.score')
            ->orderByDesc('pulse_signals.published_at')
            ->orderByDesc('pulse_signals.id')
            ->limit(self::POOL)
            ->get(['pulse_signals.*', 'm.id as match_id', 'm.score as match_score', 'm.reasons as match_reasons']);

        $pack = SignalPack::build($rows);
        $ctx = $this->contexts->loadExclusions($u->id);
        $matches = [];
        $items = collect();

        foreach ($rows as $signal) {
            if (! $this->matcher->eligible($ctx, $signal, $pack)) {
                continue;
            }

            $reasons = $signal->match_reasons;
            $reasons = is_string($reasons) ? (json_decode($reasons, true) ?: []) : (array) $reasons;

            if (! $this->inMode($mode, $signal, $reasons)) {
                continue;
            }

            $matches[$signal->id] = ['id' => (int) $signal->match_id, 'score' => (float) $signal->match_score, 'reasons' => $reasons];
            $items->push(['signal' => $signal, 'id' => $signal->id]);
        }

        return ['items' => collect($this->diversify($items->all())), 'pack' => $pack, 'matches' => $matches];
    }

    /** @param array<int, mixed> $reasons */
    private function inMode(string $mode, PulseSignal $signal, array $reasons): bool
    {
        $codes = array_column(array_filter($reasons, 'is_array'), 'code');

        return match ($mode) {
            'local' => in_array('place_city', $codes, true) || in_array('place_region', $codes, true),
            'trade', 'work' => $signal->kind !== 'collab' || Taxonomy::modeOf('collab', Taxonomy::normalise((array) $signal->topics)) === $mode,
            default => true,
        };
    }

    /**
     * Best first, but never more than RUN_LIMIT in a row of one kind or one business while anything else is left.
     *
     * Greedy by score, with one look ahead: a pick is skipped if it would leave a kind (or a business) with more
     * items than can still be spread out between everything else, which is what makes a long tail of one kind
     * unavoidable. Only when no such pick exists does the rule give way, and then only for what is genuinely left.
     *
     * @param  list<array{signal: PulseSignal, id: int}>  $items
     * @return list<array{signal: PulseSignal, id: int}>
     */
    private function diversify(array $items): array
    {
        $out = [];
        $remaining = array_values($items);

        $kindLeft = $entityLeft = [];
        foreach ($remaining as $item) {
            $kindLeft[$item['signal']->kind] = ($kindLeft[$item['signal']->kind] ?? 0) + 1;
            if ($item['signal']->entity_id !== null) {
                $entityLeft[(int) $item['signal']->entity_id] = ($entityLeft[(int) $item['signal']->entity_id] ?? 0) + 1;
            }
        }

        while ($remaining !== []) {
            $total = count($remaining);
            $pick = $fallback = null;

            foreach ($remaining as $i => $item) {
                $signal = $item['signal'];

                if ($this->breaksRun($out, $signal)) {
                    continue;
                }

                $fallback ??= $i;
                $kLeft = $kindLeft;
                $eLeft = $entityLeft;
                $kLeft[$signal->kind]--;
                if ($signal->entity_id !== null) {
                    $eLeft[(int) $signal->entity_id]--;
                }

                $tail = array_merge($out, [$item]);

                if ($this->spreadable($tail, $kLeft, $total - 1, fn ($s) => $s->kind)
                    && $this->spreadable($tail, $eLeft, $total - 1, fn ($s) => $s->entity_id === null ? null : (int) $s->entity_id)) {
                    $pick = $i;
                    break;
                }
            }

            $pick ??= $fallback ?? array_key_first($remaining);

            $chosen = $remaining[$pick];
            $out[] = $chosen;
            $kindLeft[$chosen['signal']->kind]--;
            if ($chosen['signal']->entity_id !== null) {
                $entityLeft[(int) $chosen['signal']->entity_id]--;
            }
            unset($remaining[$pick]);
        }

        return $out;
    }

    /**
     * Whether what is left of each group can still be placed without a run longer than RUN_LIMIT: a group of n items
     * fits when n is at most the room left in the current run plus RUN_LIMIT for every item that is not in the group.
     *
     * @param  list<array{signal: PulseSignal, id: int}>  $out  the sequence so far, including the candidate pick
     * @param  array<int|string, int>  $left  items left per group after the pick
     */
    private function spreadable(array $out, array $left, int $totalLeft, callable $key): bool
    {
        $last = $out === [] ? null : $key(end($out)['signal']);
        $run = 0;

        for ($i = count($out) - 1; $i >= 0 && $key($out[$i]['signal']) === $last && $last !== null; $i--) {
            $run++;
        }

        foreach ($left as $group => $n) {
            if ($n <= self::RUN_LIMIT) {
                continue;
            }

            $others = $totalLeft - $n;
            $room = ($last !== null && (string) $last === (string) $group) ? max(0, self::RUN_LIMIT - $run) : self::RUN_LIMIT;

            if ($n > $room + self::RUN_LIMIT * $others) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{signal: PulseSignal, id: int}> $out */
    private function breaksRun(array $out, PulseSignal $next): bool
    {
        if (count($out) < self::RUN_LIMIT) {
            return false;
        }

        $tail = array_slice($out, -self::RUN_LIMIT);
        $sameKind = collect($tail)->every(fn ($i) => $i['signal']->kind === $next->kind);
        $sameEntity = $next->entity_id !== null && collect($tail)->every(fn ($i) => (int) $i['signal']->entity_id === (int) $next->entity_id);

        return $sameKind || $sameEntity;
    }

    // -- bits ---------------------------------------------------------------------------------------------------

    private function assertVisible(User $u, PulseSignal $s): void
    {
        $ctx = $this->contexts->loadExclusions($u->id);
        $pack = SignalPack::build(collect([$s]));

        if (! $this->matcher->eligible($ctx, $s, $pack)) {
            throw new AuthorizationException('That is not available to you.');
        }
    }

    /**
     * @param  list<int>  $signalIds
     * @return array<int, array{id: int, score: float, reasons: array<int, mixed>}>
     */
    private function storedMatches(int $userId, array $signalIds): array
    {
        $out = [];

        foreach (PulseMatch::where('user_id', $userId)->whereIn('signal_id', $signalIds ?: [0])->get() as $m) {
            $out[$m->signal_id] = ['id' => $m->id, 'score' => $m->score, 'reasons' => (array) $m->reasons];
        }

        return $out;
    }

    /** Matches are recomputed on the ten minute mark; that is when a new round can start. */
    private function nextRoundAt(): Carbon
    {
        $now = now();

        return $now->copy()->startOfMinute()->addMinutes(10 - ($now->minute % 10));
    }

    private function encode(int $offset): string
    {
        return rtrim(strtr(base64_encode('o:'.$offset), '+/', '-_'), '=');
    }

    private function decode(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }

        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);

        return ($raw !== false && preg_match('/^o:(\d{1,6})$/', $raw, $m)) ? (int) $m[1] : 0;
    }
}
