<?php

namespace App\Services\Pulse\Discovery;

use App\Models\PulsePlace;
use App\Models\PulseSignal;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * K-anonymity for anonymous talent cards (D23).
 *
 * An anonymous professional's card is shown to an employer only when at least `pulse.privacy.talent_cohort_min`
 * professionals (5 by default) look like them on the card's STABLE attributes: they list every one of the card's
 * primary skills (its first three) and sit in the same region. The cohort is open or anonymous, adult confirmed,
 * live, and leaves out anyone who excludes this employer (hides from it, blocked it, was blocked by it, belongs to it).
 *
 * Why the cohort is not computed over the search: an employer who narrows filters until one card is left must learn
 * nothing from the narrowing. A card is in or out for a given employer whatever they typed, so two searches that
 * differ by one filter cannot differ by one person. The talent-side search filters are held to the same attributes
 * (skills and region); free text such as the headline and the experience band, and the remote switch, never select
 * an anonymous card (see SearchService).
 *
 * Open-mode professionals chose to be found by name and are never held back by this rule; they still count towards
 * other people's cohorts.
 */
class AnonymousCohort
{
    /** How many of a card's skills (in the order the person listed them) make up its primary skill set. */
    public const PRIMARY_SKILLS = 3;

    /** @var WeakMap<UserContext, array<int, true>>|null */
    private static ?WeakMap $memo = null;

    public static function min(): int
    {
        return max(1, (int) config('pulse.privacy.talent_cohort_min', 5));
    }

    /** Whether this employer may be shown this anonymous talent card. Open-mode and other signals are not this rule's business. */
    public function allows(UserContext $ctx, PulseSignal $signal): bool
    {
        if (self::min() <= 1) {
            return true;
        }

        return isset($this->allowedFor($ctx)[(int) $signal->id]);
    }

    /**
     * Ids of the anonymous talent signals this employer may be shown. One pass over the live talent signals, a fixed
     * number of queries whatever the number of cards; remembered for the life of the context object.
     *
     * @return array<int, true>
     */
    public function allowedFor(UserContext $ctx): array
    {
        self::$memo ??= new WeakMap;

        if (isset(self::$memo[$ctx])) {
            return self::$memo[$ctx];
        }

        return self::$memo[$ctx] = $this->compute($ctx);
    }

    /** For tests that change the data under a context that is still in use. */
    public static function flush(): void
    {
        self::$memo = null;
    }

    /** @return array<int, true> */
    private function compute(UserContext $ctx): array
    {
        $rows = DB::table('pulse_signals as s')
            ->join('pulse_profiles as p', 'p.user_id', '=', 's.owner_id')
            ->where('s.kind', 'talent')->where('s.status', 'live')
            ->where(fn ($w) => $w->whereNull('s.expires_at')->orWhere('s.expires_at', '>', now()))
            ->whereIn('p.discoverability', ['anonymous', 'open'])
            ->whereNotNull('p.age_confirmed_at')
            ->get(['s.id', 's.owner_id', 's.skills', 's.place_id', 'p.work_history', 'p.discoverability']);

        if ($rows->isEmpty()) {
            return [];
        }

        $excluded = $ctx->blockedUserIds + [$ctx->userId => true];

        if ($ctx->ownEntityIds !== []) {
            $pages = array_keys($ctx->ownEntityIds);

            foreach (DB::table('pulse_entity_members')->whereIn('entity_id', $pages)->pluck('user_id') as $id) {
                $excluded[(int) $id] = true;
            }

            foreach (DB::table('pulse_entity_blocks')->whereIn('entity_id', $pages)->pluck('user_id') as $id) {
                $excluded[(int) $id] = true;
            }
        }

        $regions = $this->regionKeys($rows->pluck('place_id')->filter()->unique()->all());

        // region key => skill => member row indexes (the inverted index that answers "who lists all of these skills")
        $index = [];
        $anonymous = [];

        foreach ($rows as $i => $row) {
            if (isset($excluded[(int) $row->owner_id]) || $this->hidesFromEmployer($row->work_history, $ctx)) {
                continue;
            }

            $skills = Taxonomy::normalise(is_string($row->skills) ? (json_decode($row->skills, true) ?: []) : (array) $row->skills, 15);
            $region = $row->place_id ? ($regions[(int) $row->place_id] ?? 'none') : 'none';

            foreach ($skills as $skill) {
                $index[$region][$skill][$i] = true;
            }

            if ($row->discoverability === 'anonymous') {
                $anonymous[(int) $row->id] = [$region, array_slice($skills, 0, self::PRIMARY_SKILLS)];
            }
        }

        $min = self::min();
        $allowed = [];
        $sized = [];

        foreach ($anonymous as $signalId => [$region, $primary]) {
            // A card that lists no skills has nothing to share with anyone: it cannot be placed in a cohort.
            if ($primary === []) {
                continue;
            }

            $key = $region.'|'.implode(',', $primary);

            if (! isset($sized[$key])) {
                $sets = array_map(fn ($skill) => $index[$region][$skill] ?? [], $primary);
                $sized[$key] = count(array_intersect_key(...$sets));
            }

            if ($sized[$key] >= $min) {
                $allowed[$signalId] = true;
            }
        }

        return $allowed;
    }

    /**
     * @param  list<int>  $placeIds
     * @return array<int, string> place id => region key (country code and region name; a country when there is no region)
     */
    private function regionKeys(array $placeIds): array
    {
        if ($placeIds === []) {
            return [];
        }

        $out = [];

        foreach (PulsePlace::query()->whereIn('id', $placeIds)->get(['id', 'level', 'country_code', 'region', 'name']) as $place) {
            $out[(int) $place->id] = match (true) {
                $place->level === 'country' => 'c:'.$place->country_code,
                filled($place->region) => 'r:'.$place->country_code.':'.mb_strtolower((string) $place->region),
                default => 'c:'.$place->country_code,
            };
        }

        return $out;
    }

    private function hidesFromEmployer(mixed $history, UserContext $ctx): bool
    {
        if ($ctx->employerNames === []) {
            return false;
        }

        $rows = is_string($history) ? (json_decode($history, true) ?: []) : (array) $history;

        foreach ($rows as $job) {
            if (is_array($job) && ! empty($job['hide_from_employer']) && in_array(mb_strtolower(trim((string) ($job['employer'] ?? ($job['org'] ?? '')))), $ctx->employerNames, true)) {
                return true;
            }
        }

        return false;
    }
}
