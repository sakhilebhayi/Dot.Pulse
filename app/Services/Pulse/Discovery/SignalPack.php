<?php

namespace App\Services\Pulse\Discovery;

use App\Models\PulsePlace;
use App\Models\PulseSignal;
use App\Services\Pulse\Discovery\Support\SubjectTypes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything about a set of signals that is not on the signal row itself, fetched once for the whole set with a
 * fixed number of queries: the business page, the subject rows the rules need (a need's visibility, a role's
 * seniority, a talent profile's discoverability and age gate), places, shared communities and reply behaviour.
 */
final class SignalPack
{
    /** @var array<int, object> entity id => row */
    public array $entities = [];

    /** @var array<int, list<int>> entity id => owner user ids (owners and the creator) */
    public array $entityOwners = [];

    /** @var array<int, object> need id => row */
    public array $needs = [];

    /** @var array<int, object> role id => row */
    public array $roles = [];

    /** @var array<int, object> profile id => row */
    public array $profiles = [];

    /** @var array<int, object> user id => profile row, for talent signals */
    public array $profilesByUser = [];

    /** @var array<int, PulsePlace> */
    public array $places = [];

    /** @var array<int, array<int, true>> owner id => community ids */
    public array $ownerCommunities = [];

    /** @var array<int, bool> entity id => replies quickly and reliably */
    public array $responsive = [];

    /** @var array<int, string> user id => display name (only loaded on request, only for non-anonymous people) */
    public array $names = [];

    private static ?bool $hasAgeColumn = null;

    /** @param Collection<int, PulseSignal> $signals */
    public static function build(Collection $signals): self
    {
        $pack = new self;

        if ($signals->isEmpty()) {
            return $pack;
        }

        $entityIds = $signals->pluck('entity_id')->filter()->unique()->values()->all();
        $ownerIds = $signals->pluck('owner_id')->filter()->unique()->values()->all();

        if ($entityIds !== []) {
            foreach (DB::table('pulse_entities')->whereIn('id', $entityIds)->whereNull('deleted_at')
                ->get(['id', 'slug', 'name', 'status', 'created_by', 'contact_policy', 'verification_status', 'verification_source', 'verified_at', 'registration_number', 'logo_media_id', 'industry']) as $e) {
                $pack->entities[(int) $e->id] = $e;
                $pack->entityOwners[(int) $e->id] = [(int) $e->created_by];
            }

            foreach (DB::table('pulse_entity_members')->whereIn('entity_id', $entityIds)->where('role', 'owner')->get(['entity_id', 'user_id']) as $m) {
                $pack->entityOwners[(int) $m->entity_id][] = (int) $m->user_id;
            }
        }

        foreach (['need' => 'pulse_needs', 'role' => 'pulse_roles'] as $type => $table) {
            $ids = self::subjectIds($signals, $type);

            if ($ids === []) {
                continue;
            }

            $columns = $type === 'need'
                ? ['id', 'user_id', 'visibility', 'kind', 'status']
                : ['id', 'seniority', 'work_mode', 'employment_type', 'status'];

            foreach (DB::table($table)->whereIn('id', $ids)->get($columns) as $row) {
                if ($type === 'need') {
                    $pack->needs[(int) $row->id] = $row;
                } else {
                    $pack->roles[(int) $row->id] = $row;
                }
            }
        }

        // Talent: the profile row decides discoverability, the age gate and hidden employers.
        $talent = $signals->filter(fn ($s) => $s->kind === 'talent' || $s->anonymous);
        $profileColumns = ['id', 'user_id', 'discoverability', 'experience_years', 'work_history', 'place_id'];

        if (self::hasAgeColumn()) {
            $profileColumns[] = 'age_confirmed_at';
        }

        $profileIds = self::subjectIds($talent, 'profile');
        $talentOwners = $talent->pluck('owner_id')->filter()->unique()->values()->all();

        if ($profileIds !== [] || $talentOwners !== []) {
            $q = DB::table('pulse_profiles')->where(function ($w) use ($profileIds, $talentOwners) {
                $w->whereIn('id', $profileIds ?: [0])->orWhereIn('user_id', $talentOwners ?: [0]);
            });

            foreach ($q->get($profileColumns) as $p) {
                $pack->profiles[(int) $p->id] = $p;
                $pack->profilesByUser[(int) $p->user_id] = $p;
            }
        }

        $placeIds = $signals->pluck('place_id')->filter()->unique()->values()->all();
        if ($placeIds !== []) {
            $pack->places = PulsePlace::whereIn('id', $placeIds)->get()->keyBy('id')->all();
        }

        if ($ownerIds !== []) {
            foreach (DB::table('community_memberships')->whereIn('user_id', $ownerIds)->get(['user_id', 'community_id']) as $c) {
                $pack->ownerCommunities[(int) $c->user_id][(int) $c->community_id] = true;
            }
        }

        $pack->responsive = self::responsiveEntities($entityIds);

        return $pack;
    }

    /** Display names for the given users, loaded on demand so anonymous people are never fetched by accident. */
    public function loadNames(array $userIds): void
    {
        $userIds = array_values(array_diff(array_unique(array_map('intval', $userIds)), array_keys($this->names)));

        if ($userIds === []) {
            return;
        }

        foreach (DB::table('users')->whereIn('id', $userIds)->get(['id', 'name']) as $u) {
            $this->names[(int) $u->id] = (string) $u->name;
        }
    }

    public function place(?int $id): ?PulsePlace
    {
        return $id ? ($this->places[$id] ?? null) : null;
    }

    public function profileOf(PulseSignal $s): ?object
    {
        $type = SubjectTypes::normalise($s->subject_type);

        if ($type === 'profile' && isset($this->profiles[(int) $s->subject_id])) {
            return $this->profiles[(int) $s->subject_id];
        }

        return $s->owner_id ? ($this->profilesByUser[(int) $s->owner_id] ?? null) : null;
    }

    public static function hasAgeColumn(): bool
    {
        return self::$hasAgeColumn ??= Schema::hasColumn('pulse_profiles', 'age_confirmed_at');
    }

    public static function flushSchemaCache(): void
    {
        self::$hasAgeColumn = null;
    }

    /**
     * @param  Collection<int, PulseSignal>  $signals
     * @return list<int>
     */
    private static function subjectIds(Collection $signals, string $type): array
    {
        return $signals->filter(fn ($s) => SubjectTypes::normalise((string) $s->subject_type) === $type)
            ->pluck('subject_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /**
     * A business "replies" when, over the last 90 days, at least 3 conversations opened with it and 70 percent got a
     * first response within 24 hours. Built defensively from the conversation table that the Lines module fills;
     * where there is no data the answer is simply "no", never a guess.
     *
     * @param  list<int>  $entityIds
     * @return array<int, bool>
     */
    private static function responsiveEntities(array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }

        try {
            $rows = DB::table('pulse_conversations')
                ->whereIn('entity_id', $entityIds)
                ->where('created_at', '>=', now()->subDays(90))
                ->orderByDesc('id')
                ->limit(4000)
                ->get(['entity_id', 'created_at', 'first_response_at']);
        } catch (\Throwable) {
            return [];
        }

        $out = [];

        foreach ($rows->groupBy('entity_id') as $entityId => $conversations) {
            if ($conversations->count() < 3) {
                continue;
            }

            $quick = $conversations->filter(function ($c) {
                if ($c->first_response_at === null) {
                    return false;
                }

                return strtotime((string) $c->first_response_at) - strtotime((string) $c->created_at) <= 86400;
            })->count();

            $out[(int) $entityId] = ($quick / $conversations->count()) >= 0.7;
        }

        return $out;
    }
}
