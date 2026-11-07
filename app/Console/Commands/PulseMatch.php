<?php

namespace App\Console\Commands;

use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\Matcher;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes matches. Each scheduled run handles what changed since the last finished run, in chunks, inside a time
 * budget: if it cannot finish (a big import, a slow host) it saves where it stopped and the next run carries on, and the
 * "since" mark only moves forward when everything in the window is done, so nothing is dropped. One run at a time:
 * the shared crontab starts a run every ten minutes whatever the last one is doing, so the run takes a lock.
 */
class PulseMatch extends Command
{
    protected $signature = 'pulse:match {--user= : Recompute one user id} {--all : Recompute every live signal and everyone with a profile} {--limit=400 : Most signals and most people handled per run} {--budget= : Seconds a run may take before it saves its place (default 240; unlimited with --all)}';

    protected $description = 'Recompute Dot.Pulse matches: new or changed signals against interested people, and people whose profile, needs, offers or follows changed';

    public const MARK = 'pulse:match:last-run';

    public const RESUME = 'pulse:match:resume';

    public const LOCK = 'pulse:match:run';

    /** Signals per forSignals() call. Memory stays flat: nothing is kept between batches. */
    private const SIGNAL_BATCH = 50;

    /** People per forUser() batch. */
    private const USER_BATCH = 100;

    public function handle(Matcher $matcher): int
    {
        $limit = max(1, (int) $this->option('limit'));

        if ($id = $this->option('user')) {
            $user = User::find($id);

            if ($user === null) {
                $this->error("No user {$id}.");

                return self::FAILURE;
            }

            $this->info("Matches written: {$matcher->forUser($user)}");

            return self::SUCCESS;
        }

        $lock = $this->lock();

        if ($lock === false) {
            $this->info('Another pulse:match run is still going; this tick is skipped.');

            return self::SUCCESS;
        }

        try {
            return $this->runCycle($matcher, $limit);
        } finally {
            $lock?->release();
        }
    }

    private function runCycle(Matcher $matcher, int $limit): int
    {
        $all = (bool) $this->option('all');
        $budget = $this->option('budget') !== null ? (int) $this->option('budget') : ($all ? 0 : 240);
        $deadline = $budget > 0 ? microtime(true) + $budget : null;
        $outOfTime = fn () => $deadline !== null && microtime(true) >= $deadline;

        $resume = $all ? null : $this->resume();
        $started = $resume['started'] ?? now();
        $since = $all ? null : ($resume['since'] ?? $this->since());
        $signalAfter = (int) ($resume['signal_after'] ?? 0);
        $userAfter = (int) ($resume['user_after'] ?? 0);

        // 1. New or changed signals go to the people who might care.
        $written = $signalCount = 0;
        $moreSignals = false;

        while (true) {
            $take = $all ? self::SIGNAL_BATCH : min(self::SIGNAL_BATCH, $limit - $signalCount);

            if ($take <= 0 || $outOfTime()) {
                $moreSignals = $this->signalsAfter($signalAfter, $since)->exists();
                break;
            }

            $batch = $this->signalsAfter($signalAfter, $since)->limit($take)->get();

            if ($batch->isEmpty()) {
                break;
            }

            $written += $matcher->forSignals($batch);
            $signalCount += $batch->count();
            $signalAfter = (int) $batch->last()->id;
        }

        // 2. People whose own inputs changed (or who have no matches yet) get a full recompute.
        $userIds = array_values(array_filter($all ? $this->everyone() : $this->activeUserIds($since, $limit), fn (int $id) => $id > $userAfter));
        sort($userIds);
        $userCount = 0;
        $moreUsers = false;

        foreach (array_chunk($userIds, self::USER_BATCH) as $chunk) {
            if ((! $all && $userCount >= $limit) || $outOfTime()) {
                $moreUsers = true;
                break;
            }

            if (! $all) {
                $chunk = array_slice($chunk, 0, $limit - $userCount);
            }

            foreach (User::query()->whereIn('id', $chunk)->orderBy('id')->get(['id']) as $user) {
                $written += $matcher->forUser($user);
            }

            $userCount += count($chunk);
            $userAfter = (int) end($chunk);
        }

        if (! $all) {
            $this->settle($moreSignals || $moreUsers, $started, $since, $signalAfter, $userAfter);
        }

        $tail = ($moreSignals || $moreUsers) ? ' (more to do: the next run carries on)' : '';
        $this->info("Signals matched: {$signalCount}, people recomputed: {$userCount}, match rows written: {$written}{$tail}");

        return self::SUCCESS;
    }

    private function signalsAfter(int $after, ?CarbonInterface $since)
    {
        return PulseSignal::query()->where('status', 'live')->where('id', '>', $after)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))->orderBy('id');
    }

    /** Move the mark when the window is finished; otherwise remember where this run stopped. */
    private function settle(bool $unfinished, CarbonInterface $started, ?CarbonInterface $since, int $signalAfter, int $userAfter): void
    {
        try {
            if ($unfinished) {
                Cache::put(self::RESUME, [
                    'started' => $started->toIso8601String(), 'since' => $since?->toIso8601String(),
                    'signal_after' => $signalAfter, 'user_after' => $userAfter,
                ], now()->addDay());

                return;
            }

            Cache::forget(self::RESUME);
            Cache::put(self::MARK, $started->toIso8601String(), now()->addDay());
        } catch (\Throwable) {
            // The window then simply defaults to the last half hour next time.
        }
    }

    /** @return array{started: CarbonInterface, since: ?CarbonInterface, signal_after: int, user_after: int}|null */
    private function resume(): ?array
    {
        try {
            $state = Cache::get(self::RESUME);

            if (is_array($state) && isset($state['started'])) {
                return [
                    'started' => Carbon::parse($state['started']),
                    'since' => isset($state['since']) ? Carbon::parse($state['since']) : null,
                    'signal_after' => (int) ($state['signal_after'] ?? 0),
                    'user_after' => (int) ($state['user_after'] ?? 0),
                ];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** The run lock: null when the cache has no locks (the run goes ahead unguarded), false when a run holds it. */
    private function lock(): Lock|null|false
    {
        try {
            $lock = Cache::lock(self::LOCK, 900);

            return $lock->get() ? $lock : false;
        } catch (\Throwable) {
            return null;
        }
    }

    private function since(): CarbonInterface
    {
        try {
            $mark = Cache::get(self::MARK);

            if ($mark) {
                return Carbon::parse($mark)->subMinute();
            }
        } catch (\Throwable) {
        }

        return now()->subMinutes(30);
    }

    /** @return list<int> */
    private function everyone(): array
    {
        return DB::table('pulse_profiles')->orderBy('user_id')->pluck('user_id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return list<int> */
    private function activeUserIds(?CarbonInterface $since, int $limit): array
    {
        $ids = collect();
        $ids = $ids->merge(DB::table('pulse_profiles')->where('updated_at', '>=', $since)->pluck('user_id'));
        $ids = $ids->merge(DB::table('pulse_needs')->where('updated_at', '>=', $since)->pluck('user_id'));
        $ids = $ids->merge(DB::table('pulse_entity_followers')->where('created_at', '>=', $since)->pluck('user_id'));
        $ids = $ids->merge(DB::table('pulse_mutes')->where('updated_at', '>=', $since)->pluck('user_id'));
        $ids = $ids->merge(
            DB::table('pulse_entity_members as m')->join('pulse_entities as e', 'e.id', '=', 'm.entity_id')
                ->where(function ($q) use ($since) {
                    $q->where('e.updated_at', '>=', $since)
                        ->orWhereIn('e.id', fn ($s) => $s->select('entity_id')->from('pulse_offers')->where('updated_at', '>=', $since))
                        ->orWhereIn('e.id', fn ($s) => $s->select('entity_id')->from('pulse_roles')->where('updated_at', '>=', $since));
                })->pluck('m.user_id'),
        );

        // Anyone with a profile who has no matches at all yet (new people see something on their first visit).
        $ids = $ids->merge(
            DB::table('pulse_profiles')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('pulse_matches')->whereColumn('pulse_matches.user_id', 'pulse_profiles.user_id'))
                ->orderBy('user_id')->limit($limit * 4)->pluck('user_id'),
        );

        return $ids->map(fn ($v) => (int) $v)->unique()->values()->all();
    }
}
