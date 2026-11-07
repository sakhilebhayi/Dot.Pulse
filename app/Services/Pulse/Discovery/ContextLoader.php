<?php

namespace App\Services\Pulse\Discovery;

use App\Models\PulsePlace;
use App\Services\Pulse\Discovery\Places\PlaceRepository;
use Illuminate\Support\Facades\DB;

/**
 * Builds UserContext objects for many people with a fixed number of queries (about eleven for the whole batch),
 * so recomputing matches for a hundred people costs the same round trips as one.
 */
class ContextLoader
{
    /** The only profile columns matching reads. Deliberately explicit: new columns are never picked up by accident. */
    public const PROFILE_COLUMNS = ['user_id', 'skills', 'expertise_tags', 'interests', 'open_to', 'place_id', 'reach', 'experience_years'];

    public function __construct(private readonly PlaceRepository $places) {}

    /**
     * @param  list<int>  $userIds
     * @return array<int, UserContext>
     */
    public function load(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if ($userIds === []) {
            return [];
        }

        $contexts = [];
        foreach ($userIds as $id) {
            $contexts[$id] = new UserContext($id);
        }

        $now = now();

        // 1. Professional profile: declared skills, interests, place and reach. No age, no postcode.
        $profiles = DB::table('pulse_profiles')->whereIn('user_id', $userIds)->get(self::PROFILE_COLUMNS)->keyBy('user_id');

        // 3. Open needs.
        $needs = DB::table('pulse_needs')
            ->whereIn('user_id', $userIds)
            ->where('status', 'open')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->get(['user_id', 'kind', 'title', 'tags', 'place_id', 'reach'])
            ->groupBy('user_id');

        // 4. Business pages the person works for.
        $memberships = DB::table('pulse_entity_members as m')
            ->join('pulse_entities as e', 'e.id', '=', 'm.entity_id')
            ->whereIn('m.user_id', $userIds)
            ->whereNull('e.deleted_at')
            ->where('e.status', '!=', 'suspended')
            ->get(['m.user_id', 'e.id as entity_id', 'e.name', 'e.is_employer', 'e.is_seller', 'e.hiring_signal', 'e.hiring_skills', 'e.place_id', 'e.industry'])
            ->groupBy('user_id');

        $entityIds = $memberships->flatten(1)->pluck('entity_id')->unique()->values()->all();

        // 5. Their live offers and 6. live roles.
        $offers = $entityIds === [] ? collect() : DB::table('pulse_offers')
            ->whereIn('entity_id', $entityIds)->where('status', 'live')->whereNull('deleted_at')
            ->get(['entity_id', 'category', 'tags', 'title'])->groupBy('entity_id');

        $roles = $entityIds === [] ? collect() : DB::table('pulse_roles')
            ->whereIn('entity_id', $entityIds)->where('status', 'live')->whereNull('deleted_at')
            ->get(['entity_id', 'skills', 'work_mode', 'seniority', 'place_id'])->groupBy('entity_id');

        // Places referenced by anything above, in one query.
        $placeIds = collect([$profiles->pluck('place_id'), $needs->flatten(1)->pluck('place_id'), $memberships->flatten(1)->pluck('place_id'), $roles->flatten(1)->pluck('place_id')])
            ->flatten()->filter()->unique()->values()->all();
        $placeMap = $placeIds === [] ? collect() : PulsePlace::whereIn('id', $placeIds)->get()->keyBy('id');

        // 7. Follows of businesses, 8. follows of people, 11. communities.
        $entityFollows = DB::table('pulse_entity_followers')->whereIn('user_id', $userIds)->get(['user_id', 'entity_id'])->groupBy('user_id');
        $userFollows = DB::table('pulse_followers')->whereIn('follower_id', $userIds)->get(['follower_id', 'following_id'])->groupBy('follower_id');
        $communities = DB::table('community_memberships')->whereIn('user_id', $userIds)->get(['user_id', 'community_id'])->groupBy('user_id');

        // 9. Mutes and 10. blocks (both directions).
        $mutes = DB::table('pulse_mutes')->whereIn('user_id', $userIds)->get(['user_id', 'kind', 'target'])->groupBy('user_id');
        $blocks = DB::table('pulse_blocks')->whereIn('blocker_id', $userIds)->orWhereIn('blocked_id', $userIds)->get(['blocker_id', 'blocked_id']);

        foreach ($contexts as $id => $ctx) {
            $p = $profiles->get($id);

            if ($p !== null) {
                $ctx->place = $p->place_id ? $placeMap->get($p->place_id) : null;
                $ctx->reach = in_array($p->reach ?? null, ['city', 'region', 'country', 'remote'], true) ? $p->reach : 'region';
                $ctx->skills = Taxonomy::normalise(array_merge($this->json($p->skills), $this->json($p->expertise_tags)));
                $ctx->interests = Taxonomy::normalise($this->json($p->interests));
                $ctx->openTo = array_values(array_filter(array_map('strval', $this->json($p->open_to))));
                $band = Taxonomy::experienceBand(isset($p->experience_years) ? (int) $p->experience_years : null);
                $ctx->experienceBand = $band['key'] ?? null;
            }

            foreach ($needs->get($id, collect()) as $n) {
                $place = $n->place_id ? $placeMap->get($n->place_id) : $ctx->place;
                $ctx->needs[] = [
                    'topics' => array_values(array_unique(array_merge(Taxonomy::normalise($this->json($n->tags)), Taxonomy::extract((string) $n->title)))),
                    'place' => $place,
                    'max_rank' => $this->reachRank((string) $n->reach),
                    'kind' => (string) $n->kind,
                ];
            }

            foreach ($memberships->get($id, collect()) as $m) {
                $ctx->ownEntityIds[(int) $m->entity_id] = true;
                $ctx->employerNames[] = mb_strtolower((string) $m->name);

                foreach ($offers->get($m->entity_id, collect()) as $o) {
                    $ctx->offerTopics = array_merge($ctx->offerTopics, array_merge(Taxonomy::normalise(array_merge($this->json($o->tags), [(string) $o->category])), Taxonomy::extract((string) $o->title)));
                }

                if ($m->is_employer) {
                    $ctx->isEmployer = true;

                    foreach ($roles->get($m->entity_id, collect()) as $r) {
                        $ctx->seeks[] = [
                            'skills' => Taxonomy::normalise($this->json($r->skills)),
                            'band' => Taxonomy::seniorityBand($r->seniority),
                            'remote' => $r->work_mode === 'remote',
                            'place' => $r->place_id ? $placeMap->get($r->place_id) : ($m->place_id ? $placeMap->get($m->place_id) : null),
                            'entity_id' => (int) $m->entity_id,
                        ];
                    }

                    // An employer open to meeting talent before any vacancy exists (passive discovery).
                    if (in_array($m->hiring_signal, ['building', 'active'], true)) {
                        $skills = Taxonomy::normalise($this->json($m->hiring_skills));
                        if ($skills !== []) {
                            $ctx->seeks[] = ['skills' => $skills, 'band' => null, 'remote' => false, 'place' => $m->place_id ? $placeMap->get($m->place_id) : null, 'entity_id' => (int) $m->entity_id];
                        }
                    }
                }
            }
            $ctx->offerTopics = array_values(array_unique($ctx->offerTopics));

            foreach ($entityFollows->get($id, collect()) as $f) {
                $ctx->followedEntityIds[(int) $f->entity_id] = true;
            }
            foreach ($userFollows->get($id, collect()) as $f) {
                $ctx->followedUserIds[(int) $f->following_id] = true;
            }
            foreach ($communities->get($id, collect()) as $c) {
                $ctx->communityIds[(int) $c->community_id] = true;
            }

            foreach ($mutes->get($id, collect()) as $m) {
                $target = (string) $m->target;
                match ($m->kind) {
                    'topic' => $ctx->mutedTopics[Taxonomy::canonical($target)] = true,
                    'entity' => $ctx->mutedEntities[$target] = true,
                    'user' => $ctx->mutedUsers[$target] = true,
                    'place' => $ctx->mutedPlaces[$target] = true,
                    'input' => $ctx->mutedInputs[$target] = true,
                    default => null,
                };
            }
        }

        foreach ($blocks as $b) {
            if (isset($contexts[$b->blocker_id])) {
                $contexts[$b->blocker_id]->blockedUserIds[(int) $b->blocked_id] = true;
            }
            if (isset($contexts[$b->blocked_id])) {
                $contexts[$b->blocked_id]->blockedUserIds[(int) $b->blocker_id] = true;
            }
        }

        return $contexts;
    }

    /**
     * The cheap version for reading the Floor: only what exclusion needs (own pages, employer status, mutes and
     * blocks), in three queries. Matching inputs are not loaded.
     */
    public function loadExclusions(int $userId): UserContext
    {
        $ctx = new UserContext($userId);

        foreach (DB::table('pulse_entity_members as m')->join('pulse_entities as e', 'e.id', '=', 'm.entity_id')
            ->where('m.user_id', $userId)->whereNull('e.deleted_at')->get(['e.id', 'e.name', 'e.is_employer']) as $e) {
            $ctx->ownEntityIds[(int) $e->id] = true;
            $ctx->employerNames[] = mb_strtolower((string) $e->name);
            $ctx->isEmployer = $ctx->isEmployer || (bool) $e->is_employer;
        }

        foreach (DB::table('pulse_mutes')->where('user_id', $userId)->get(['kind', 'target']) as $m) {
            $target = (string) $m->target;
            match ($m->kind) {
                'topic' => $ctx->mutedTopics[Taxonomy::canonical($target)] = true,
                'entity' => $ctx->mutedEntities[$target] = true,
                'user' => $ctx->mutedUsers[$target] = true,
                'place' => $ctx->mutedPlaces[$target] = true,
                'input' => $ctx->mutedInputs[$target] = true,
                default => null,
            };
        }

        foreach (DB::table('pulse_blocks')->where('blocker_id', $userId)->orWhere('blocked_id', $userId)->get(['blocker_id', 'blocked_id']) as $b) {
            $ctx->blockedUserIds[(int) ($b->blocker_id === $userId ? $b->blocked_id : $b->blocker_id)] = true;
        }

        return $ctx;
    }

    public function loadOne(int $userId): UserContext
    {
        return $this->load([$userId])[$userId];
    }

    /** needs and signals use local|region|national|global; profiles use city|region|country|remote. */
    public function reachRank(string $reach): int
    {
        return match ($reach) {
            'local', 'city' => PlaceRepository::RANK_CITY,
            'region' => PlaceRepository::RANK_REGION,
            'national', 'country' => PlaceRepository::RANK_COUNTRY,
            default => PlaceRepository::RANK_WORLD,
        };
    }

    /** @return list<mixed> */
    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
