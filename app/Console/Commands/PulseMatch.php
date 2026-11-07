<?php

namespace App\Console\Commands;

use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\Matcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PulseMatch extends Command
{
    protected $signature = 'pulse:match {--user= : Recompute one user id} {--all : Recompute everyone with a profile} {--limit=400 : Most users or signals handled per run}';

    protected $description = 'Recompute Dot.Pulse matches: new or changed signals against interested people, and people whose profile, needs, offers or follows changed';

    private const MARK = 'pulse:match:last-run';

    public function handle(Matcher $matcher): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $started = now();

        if ($id = $this->option('user')) {
            $user = User::find($id);

            if ($user === null) {
                $this->error("No user {$id}.");

                return self::FAILURE;
            }

            $this->info("Matches written: {$matcher->forUser($user)}");

            return self::SUCCESS;
        }

        $since = $this->option('all') ? null : $this->since();

        // 1. New or changed signals go to the people who might care.
        $written = 0;
        $signals = PulseSignal::where('status', 'live')
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('id')->limit($limit)->get();

        foreach ($signals as $signal) {
            $written += $matcher->forSignal($signal);
        }

        // 2. People whose own inputs changed (or who have no matches yet) get a full recompute.
        $userIds = $this->activeUserIds($since, $limit);
        foreach (User::whereIn('id', $userIds)->get() as $user) {
            $written += $matcher->forUser($user);
        }

        try {
            Cache::put(self::MARK, $started->toIso8601String(), now()->addDay());
        } catch (\Throwable) {
            // The window then simply defaults to the last half hour next time.
        }

        $this->info("Signals matched: {$signals->count()}, people recomputed: ".count($userIds).", match rows written: {$written}");

        return self::SUCCESS;
    }

    private function since(): \Carbon\CarbonInterface
    {
        try {
            $mark = Cache::get(self::MARK);

            if ($mark) {
                return \Carbon\Carbon::parse($mark)->subMinute();
            }
        } catch (\Throwable) {
        }

        return now()->subMinutes(30);
    }

    /** @return list<int> */
    private function activeUserIds(?\Carbon\CarbonInterface $since, int $limit): array
    {
        if ($since === null) {
            return DB::table('pulse_profiles')->orderBy('user_id')->limit($limit)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        }

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
                ->limit($limit)->pluck('user_id'),
        );

        return $ids->map(fn ($v) => (int) $v)->unique()->take($limit)->values()->all();
    }
}
