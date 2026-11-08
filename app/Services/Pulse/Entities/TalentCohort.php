<?php

namespace App\Services\Pulse\Entities;

use App\Models\PulseEntity;
use App\Models\PulseEntityBlock;
use App\Models\PulsePlace;
use App\Models\PulseSignal;
use App\Services\Pulse\Discovery\Places\PlaceRepository;
use App\Services\Pulse\Discovery\Taxonomy;
use Illuminate\Support\Facades\DB;

/**
 * How many professionals an employer's hiring signal could reach, said in a way that cannot single anyone out.
 *
 * countFor() returns null unless at least MIN_COHORT professionals match, and then only the floor of a band: 5 (for
 * 5 to 9), 10 (10 to 24) or 25 (25 or more). It never returns an exact count, and never a count under 5.
 *
 * Who counts: professionals who are open or anonymous, confirmed 18 or over, whose card is live (approved), who share
 * at least one skill, who are within the region or look remotely. Who never counts: anyone who hides their current job
 * from this employer, anyone who blocked the page, anyone the page blocked, and the page's own team.
 */
class TalentCohort
{
    public const MIN_COHORT = 5;

    /** Band floors, smallest first. */
    public const BANDS = [5, 10, 25];

    public function __construct(private readonly PlaceRepository $places) {}

    /**
     * @param  PulseEntity|list<string>  $skills  a page (its hiring skills, place and name are used) or a list of skills
     * @param  PulseEntity|null  $employer  the page counting, when $skills is a list; used to leave out people who hide from it
     */
    public function countFor(PulseEntity|array $skills, ?PulsePlace $place = null, ?PulseEntity $employer = null): ?int
    {
        if ($skills instanceof PulseEntity) {
            $employer = $skills;
            $place ??= $skills->place;
            $skills = (array) $skills->hiring_skills;
        }

        $wanted = Taxonomy::normalise($skills);

        if ($wanted === []) {
            return null;
        }

        $nearby = $place ? $this->places->flatNearbyIds($place, 'region') : null;

        $signals = PulseSignal::query()
            ->where('kind', 'talent')->where('status', 'live')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['id', 'owner_id', 'subject_id', 'skills', 'place_id', 'is_remote'])
            ->filter(fn (PulseSignal $s) => array_intersect($wanted, Taxonomy::normalise((array) $s->skills)) !== [])
            ->filter(fn (PulseSignal $s) => $nearby === null || $s->is_remote || in_array((int) $s->place_id, $nearby, true));

        if ($signals->isEmpty()) {
            return null;
        }

        $profiles = DB::table('pulse_profiles')
            ->whereIn('id', $signals->pluck('subject_id')->all())
            ->whereIn('discoverability', ['open', 'anonymous'])
            ->whereNotNull('age_confirmed_at')
            ->get(['id', 'user_id', 'work_history'])
            ->keyBy('id');

        $excluded = [];

        if ($employer !== null) {
            $excluded = array_merge(
                DB::table('pulse_entity_members')->where('entity_id', $employer->id)->pluck('user_id')->all(),
                PulseEntityBlock::query()->where('entity_id', $employer->id)->pluck('user_id')->all(),
            );
        }

        $count = 0;

        foreach ($signals as $signal) {
            $profile = $profiles[$signal->subject_id] ?? null;

            if ($profile === null || in_array((int) $profile->user_id, array_map('intval', $excluded), true)) {
                continue;
            }

            if ($employer !== null && $this->hidesFrom($profile->work_history, $employer)) {
                continue;
            }

            $count++;
        }

        return self::band($count);
    }

    /** The band floor for a raw count, or null under the minimum. */
    public static function band(int $count): ?int
    {
        $floor = null;

        foreach (self::BANDS as $band) {
            if ($count >= $band) {
                $floor = $band;
            }
        }

        return $floor;
    }

    /** Words for a band: "5 to 9", "10 to 24", "25 or more". */
    public static function label(int $band): string
    {
        $i = array_search($band, self::BANDS, true);
        $next = $i === false ? null : (self::BANDS[$i + 1] ?? null);

        return $next === null ? $band.' or more' : $band.' to '.($next - 1);
    }

    private function hidesFrom(mixed $history, PulseEntity $employer): bool
    {
        $rows = is_string($history) ? (json_decode($history, true) ?: []) : (array) $history;
        $name = mb_strtolower(trim((string) $employer->name));

        foreach ($rows as $job) {
            if (is_array($job) && ! empty($job['hide_from_employer']) && mb_strtolower(trim((string) ($job['employer'] ?? ($job['org'] ?? '')))) === $name) {
                return true;
            }
        }

        return false;
    }
}
