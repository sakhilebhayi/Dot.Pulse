<?php

namespace App\Services\Pulse\Discovery;

use App\Models\PulseMatch;
use App\Models\PulsePlace;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\Places\PlaceRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Explainable matching. A match is a score and a list of reasons; each reason is a plain sentence the person can
 * read, and the score is exactly the sum of the reason weights (clamped to 0..100). Nothing is hidden in a model.
 *
 * Weights (the whole table; change them here and nowhere else)
 * ---------------------------------------------------------------------------------------------------------------
 *  code           weight            when
 *  need_offer     0..40  (+0..8)    your open need and their offer share topics. 40 x share of the smaller set
 *                                   covered, plus 8 x the listing quality
 *  offer_need     0..40  (+0..8)    your offer and their need share topics (same formula)
 *  skill_role     0..38  (+6)       your skills against the role's skills: 38 x min(1, hits / min(3, role skills)),
 *                                   +6 when your experience band meets the band the role's seniority asks for
 *  role_talent    0..38  (+6)       an employer's roles or hiring skills against a talent signal's skills (same)
 *  topic          0..24  (+0..4)    topics overlap your skills, interests, offers or needs; 24 x share of the
 *                                   smaller set covered, plus 4 x listing quality. News and events 18, asks you can
 *                                   answer 28
 *  follows        15                you follow the business (or the person) behind it
 *  place_city     14                same city (suburbs of one metro count as one city), inside both parties' reach
 *  place_region   9                 same region (province, state)
 *  place_country  4                 same country
 *  remote         6                 remote work or service, and you look remotely
 *  mutual_context 6                 you share a community with the author
 *  fresh          0..8              8 x (1 - age / 14 days), published in the last 14 days
 *  responsive     6                 the business usually replies within a day (needs 3+ real conversations)
 * ---------------------------------------------------------------------------------------------------------------
 * A signal is stored as a match only when it has at least one content reason (the first six), or, for offers, needs,
 * businesses, asks, events, news and collabs, a same-city or same-region place reason, and the score is at least 12.
 * Distance is the declared place hierarchy (city, region, country); there are no coordinates in the maths.
 *
 * What matching never uses: age, gender, race, religion, health, disability, marital status, or a postcode or any
 * proxy for them. The complete list of inputs is FEATURES; a test fails if a forbidden word appears in it.
 * Matching never rejects, ranks down or hides a person: it only decides which things are worth showing someone,
 * and in hiring a human always decides.
 */
class Matcher
{
    /** Every input matching reads. Nothing outside this list is used. */
    public const FEATURES = [
        'profile.skills', 'profile.expertise_tags', 'profile.interests', 'profile.open_to', 'profile.experience_band',
        'profile.declared_place', 'profile.declared_reach',
        'need.topics', 'need.declared_place', 'need.reach', 'need.visibility',
        'offer.topics', 'role.skills', 'role.seniority', 'role.work_mode', 'role.place',
        'employer.hiring_skills', 'employer.hiring_signal',
        'signal.kind', 'signal.topics', 'signal.skills', 'signal.place', 'signal.reach', 'signal.is_remote', 'signal.quality', 'signal.published_at',
        'follows.entities', 'follows.people', 'shared.communities', 'entity.reply_behaviour', 'muted.inputs', 'blocks',
    ];

    public const W_NEED_OFFER = 40;

    public const W_ROLE = 38;

    public const W_TOPIC = 24;

    public const W_TOPIC_ASK = 28;

    public const W_TOPIC_LEARN = 18;

    public const W_FOLLOWS = 15;

    public const W_PLACE = [0 => 14, 1 => 9, 2 => 4];

    public const W_REMOTE = 6;

    public const W_MUTUAL = 6;

    public const W_FRESH = 8;

    public const W_RESPONSIVE = 6;

    public const W_EXPERIENCE_FIT = 6;

    public const MIN_SCORE = 12.0;

    public const MAX_MATCHES_PER_USER = 150;

    /** Only signals published in this many days are considered. */
    public const WINDOW_DAYS = 90;

    /** Signals handled together by forSignals(): one pack and one interested-people map per group. */
    public const SIGNAL_GROUP = 25;

    private const CONTENT_CODES = ['need_offer', 'offer_need', 'skill_role', 'role_talent', 'topic', 'follows'];

    private const PLACE_ONLY_KINDS = ['offer', 'need', 'business', 'ask', 'event', 'news', 'collab'];

    public function __construct(
        private readonly ContextLoader $contexts,
        private readonly PlaceRepository $places,
    ) {}

    // -- public API ---------------------------------------------------------------------------------------------

    /** Recompute the matches of one person. Returns the number of match rows written. */
    public function forUser(User $user): int
    {
        $ctx = $this->contexts->loadOne($user->id);
        $signals = $this->candidates($ctx);
        $pack = SignalPack::build($signals);
        $this->prepareNames($ctx, $signals, $pack);

        $scored = [];

        foreach ($signals as $signal) {
            if (($m = $this->score($ctx, $signal, $pack)) !== null) {
                $scored[$signal->id] = $m;
            }
        }

        uasort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $scored = array_slice($scored, 0, self::MAX_MATCHES_PER_USER, true);

        return $this->persist($user->id, $scored, replace: true);
    }

    /** A new or changed signal: match it against the people who might care. Returns match rows written. */
    public function forSignal(PulseSignal $signal): int
    {
        return $this->forSignals(collect([$signal]));
    }

    /**
     * New or changed signals against the people who might care, with the work shared between them: the interested
     * people of a group of signals are loaded once (one context load per 200 people, not per signal and person) and
     * the rows are written in batches. Same rows as calling forSignal() on each, in far fewer queries.
     *
     * @param  Collection<int, PulseSignal>  $signals
     * @param  (callable(): bool)|null  $stop  asked between groups of signals; true ends the run early
     * @return int match rows written
     */
    public function forSignals(Collection $signals, ?callable $stop = null): int
    {
        $written = 0;
        $dead = $signals->filter(fn (PulseSignal $s) => $s->status !== 'live');

        if ($dead->isNotEmpty()) {
            DB::table('pulse_matches')->whereIn('signal_id', $dead->pluck('id')->all())->whereNull('dismissed_at')->delete();
        }

        foreach ($signals->filter(fn (PulseSignal $s) => $s->status === 'live')->values()->chunk(self::SIGNAL_GROUP) as $group) {
            if ($stop !== null && $stop()) {
                break;
            }

            $pack = SignalPack::build($group);
            $byUser = [];

            foreach ($group as $signal) {
                foreach ($this->interestedUserIds($signal) as $userId) {
                    $byUser[$userId][] = $signal;
                }
            }

            ksort($byUser);

            foreach (array_chunk(array_keys($byUser), 200) as $userIds) {
                $contexts = $this->contexts->load($userIds);
                $rows = [];
                $unmatched = [];
                $now = now();

                foreach ($userIds as $userId) {
                    foreach ($byUser[$userId] as $signal) {
                        if ($signal->owner_id !== null && ! $signal->anonymous && isset($contexts[$userId]->followedUserIds[(int) $signal->owner_id])) {
                            $pack->loadNames([(int) $signal->owner_id]);
                        }

                        $match = $this->score($contexts[$userId], $signal, $pack);

                        if ($match === null) {
                            $unmatched[$signal->id][] = $userId;

                            continue;
                        }

                        $rows[] = [
                            'user_id' => $userId, 'signal_id' => $signal->id, 'score' => $match['score'],
                            'reasons' => json_encode($match['reasons'], JSON_UNESCAPED_UNICODE), 'computed_at' => $now,
                        ];
                    }
                }

                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('pulse_matches')->upsert($chunk, ['user_id', 'signal_id'], ['score', 'reasons', 'computed_at']);
                }

                $written += count($rows);

                // A person who no longer matches loses the row, but only where there is one: look first, so a
                // fresh signal (no rows yet) costs one read instead of a delete per signal.
                if ($unmatched !== []) {
                    $existing = DB::table('pulse_matches')->whereIn('signal_id', array_keys($unmatched))->whereIn('user_id', $userIds)->whereNull('dismissed_at')
                        ->get(['signal_id', 'user_id'])->groupBy('signal_id');

                    foreach ($existing as $signalId => $found) {
                        $gone = array_values(array_intersect($found->pluck('user_id')->all(), $unmatched[$signalId] ?? []));
                        $gone === [] || DB::table('pulse_matches')->where('signal_id', $signalId)->whereIn('user_id', $gone)->whereNull('dismissed_at')->delete();
                    }
                }
            }
        }

        return $written;
    }

    /** The score and reasons for one person and one signal right now, or null when it is not a match. */
    public function forPair(User $user, PulseSignal $signal): ?array
    {
        $ctx = $this->contexts->loadOne($user->id);
        $pack = SignalPack::build(collect([$signal]));
        $this->prepareNames($ctx, collect([$signal]), $pack);

        return $this->score($ctx, $signal, $pack);
    }

    /**
     * The reasons behind a stored match, normalised for display.
     *
     * @return list<array{code: string, text: string, weight: float, ref: ?string}>
     */
    public function explain(PulseMatch $match): array
    {
        $out = [];

        foreach ((array) $match->reasons as $r) {
            if (! is_array($r) || ! isset($r['code'])) {
                continue;
            }

            $out[] = [
                'code' => (string) $r['code'],
                'text' => (string) ($r['text'] ?? ''),
                'weight' => (float) ($r['weight'] ?? 0),
                'ref' => isset($r['ref']) ? (string) $r['ref'] : null,
            ];
        }

        return $out;
    }

    // -- eligibility (shared with the Floor and the digest) -----------------------------------------------------

    /** Whether this person may be shown this signal at all, whatever its score. */
    public function eligible(UserContext $ctx, PulseSignal $s, SignalPack $pack): bool
    {
        if ($s->status !== 'live' || ($s->expires_at !== null && $s->expires_at->lte(now()))) {
            return false;
        }

        if ($s->owner_id !== null && (int) $s->owner_id === $ctx->userId) {
            return false;
        }

        if ($s->entity_id !== null) {
            $entity = $pack->entities[(int) $s->entity_id] ?? null;

            if ($entity === null || $entity->status !== 'live' || isset($ctx->ownEntityIds[(int) $s->entity_id])) {
                return false;
            }

            if (isset($ctx->mutedEntities[(string) $entity->id]) || isset($ctx->mutedEntities[(string) $entity->slug])) {
                return false;
            }

            foreach ($pack->entityOwners[(int) $s->entity_id] ?? [] as $ownerId) {
                if (isset($ctx->blockedUserIds[$ownerId]) || $ownerId === $ctx->userId) {
                    return false;
                }
            }
        }

        if ($s->owner_id !== null && (isset($ctx->blockedUserIds[(int) $s->owner_id]) || isset($ctx->mutedUsers[(string) $s->owner_id]))) {
            return false;
        }

        foreach ((array) $s->topics as $topic) {
            if (isset($ctx->mutedTopics[Taxonomy::canonical((string) $topic)])) {
                return false;
            }
        }

        if ($s->kind === 'talent' || $s->anonymous) {
            return $this->talentVisible($ctx, $s, $pack);
        }

        return true;
    }

    /**
     * Talent is found only on the professional's terms: employers only, only while the profile is discoverable,
     * only once the person has confirmed they are an adult, and never by an employer they chose to hide from.
     */
    private function talentVisible(UserContext $ctx, PulseSignal $s, SignalPack $pack): bool
    {
        if (! $ctx->isEmployer) {
            return false;
        }

        $profile = $pack->profileOf($s);

        if ($profile === null || ! in_array($profile->discoverability, ['anonymous', 'open'], true)) {
            return false;
        }

        if (SignalPack::hasAgeColumn() && empty($profile->age_confirmed_at)) {
            return false;
        }

        $history = $profile->work_history;
        $history = is_string($history) ? (json_decode($history, true) ?: []) : (array) $history;

        foreach ($history as $job) {
            if (is_array($job) && ! empty($job['hide_from_employer']) && in_array(mb_strtolower((string) ($job['employer'] ?? '')), $ctx->employerNames, true)) {
                return false;
            }
        }

        return true;
    }

    // -- scoring ------------------------------------------------------------------------------------------------

    /**
     * @return array{score: float, reasons: list<array<string, mixed>>}|null
     */
    public function score(UserContext $ctx, PulseSignal $s, SignalPack $pack): ?array
    {
        if (! $this->eligible($ctx, $s, $pack)) {
            return null;
        }

        $topics = $this->cleanTopics($ctx, Taxonomy::normalise((array) $s->topics));
        $skills = $this->cleanTopics($ctx, Taxonomy::normalise((array) $s->skills));
        $sigPlace = $pack->place($s->place_id);
        $sigMax = $this->contexts->reachRank((string) $s->reach);
        $quality = max(0.0, min(1.0, (float) $s->quality));
        $kind = (string) $s->kind;

        $reasons = [];
        $placeFor = null; // [PulsePlace|null viewerPlace, int viewerMax] the place reasons are judged against
        $inRange = true;

        $follows = $this->followsReason($ctx, $s, $pack);

        switch ($kind) {
            case 'offer':
                [$reason, $placeFor] = $this->needOffer($ctx, $topics, $quality);
                $reason ??= $this->topicReason($ctx, $topics, $quality, self::W_TOPIC, 'They offer %s, which you work in or follow.');
                break;

            case 'need':
                $visibility = (string) ($pack->needs[(int) $s->subject_id]->visibility ?? 'open');
                $reason = $this->offerNeed($ctx, $topics, $quality);
                if ($reason === null && $visibility !== 'matched') {
                    $reason = $this->topicReason($ctx, $topics, $quality, self::W_TOPIC, 'They are looking for %s, which is one of your skills.', skillsOnly: true);
                }
                break;

            case 'role':
                $reason = $this->skillRole($ctx, $s, array_values(array_unique(array_merge($skills, $topics))), $pack);
                $placeFor = [$ctx->place, $ctx->maxRank()];
                break;

            case 'talent':
                [$reason, $placeFor] = $this->roleTalent($ctx, $s, array_values(array_unique(array_merge($skills, $topics))), $pack);
                break;

            case 'ask':
                $reason = $this->topicReason($ctx, $topics, $quality, self::W_TOPIC_ASK, 'Someone is asking about %s, which you know about.', skillsOnly: true)
                    ?? $this->topicReason($ctx, $topics, $quality, self::W_TOPIC_LEARN, 'A question about %s, which you follow.');
                break;

            case 'event':
            case 'news':
                $reason = $this->topicReason($ctx, $topics, $quality, self::W_TOPIC_LEARN, ($kind === 'event' ? 'An event about %s' : 'News about %s').', which you follow.');
                break;

            case 'collab':
                $reason = $this->topicReason($ctx, $topics, $quality, self::W_TOPIC, 'Looking for a collaborator in %s, which you work in.')
                    ?? $this->openToCollab($ctx, $sigPlace);
                break;

            default: // business and anything new
                $reason = $this->needOffer($ctx, $topics, $quality, 'You need %s, they are a business in %s.')[0]
                    ?? $this->topicReason($ctx, $topics, $quality, self::W_TOPIC, 'A business in %s, which you work in or follow.');
        }

        if ($follows !== null) {
            $reasons[] = $follows;
        }

        if ($reason !== null) {
            $reasons[] = $reason;
        }

        if ($s->kind === 'need' && ($pack->needs[(int) $s->subject_id]->visibility ?? 'open') === 'matched' && ! $this->has($reasons, 'offer_need')) {
            // A "matched" need is shown only to people whose own offers match it.
            return null;
        }

        // Distance: the declared hierarchy, judged against the right side of the pair.
        $placeFor ??= [$ctx->place, $ctx->maxRank()];
        [$viewerPlace, $viewerMax] = $placeFor;

        $rank = $this->places->rank($viewerPlace, $sigPlace);
        $allowed = min($sigMax, $viewerMax);

        if ($rank === null) {
            $inRange = $sigMax >= PlaceRepository::RANK_COUNTRY || $s->is_remote;
        } else {
            $inRange = $rank <= $allowed || $s->is_remote;
        }

        if ($follows === null && ! $inRange) {
            return null;
        }

        if ($rank !== null && $rank <= $allowed && isset(self::W_PLACE[$rank]) && $sigPlace !== null && ! isset($ctx->mutedPlaces[(string) $sigPlace->id])) {
            $reasons[] = $this->placeReason($rank, $sigPlace);
        }

        if ($s->is_remote && ($ctx->reach === 'remote' || in_array($kind, ['role', 'talent'], true) || $sigMax === PlaceRepository::RANK_WORLD) && ! isset($ctx->mutedInputs['remote'])) {
            $reasons[] = $this->reason('remote', 'They work remotely, so distance does not matter.', self::W_REMOTE);
        }

        $hasContent = $this->hasAny($reasons, self::CONTENT_CODES);
        $placeQualifies = in_array($kind, self::PLACE_ONLY_KINDS, true)
            && ($this->has($reasons, 'place_city') || $this->has($reasons, 'place_region'));

        if (! $hasContent && ! $placeQualifies) {
            return null;
        }

        if ($s->owner_id !== null && ! $s->anonymous && $this->sharesCommunity($ctx, $s, $pack)) {
            $reasons[] = $this->reason('mutual_context', 'You are both in the same community.', self::W_MUTUAL);
        }

        if ($s->published_at !== null && ($fresh = $this->freshReason($s)) !== null) {
            $reasons[] = $fresh;
        }

        if ($s->entity_id !== null && ! empty($pack->responsive[(int) $s->entity_id])) {
            $reasons[] = $this->reason('responsive', 'They usually reply within a day.', self::W_RESPONSIVE);
        }

        $score = round(max(0.0, min(100.0, array_sum(array_column($reasons, 'weight')))), 1);

        if ($score < self::MIN_SCORE) {
            return null;
        }

        usort($reasons, fn ($a, $b) => $b['weight'] <=> $a['weight']);

        return ['score' => $score, 'reasons' => $reasons];
    }

    // -- reasons ------------------------------------------------------------------------------------------------

    /**
     * Your open need against their offer (or business). Picks the best need that is also in range.
     *
     * @param  list<string>  $topics
     * @return array{0: ?array<string, mixed>, 1: ?array{0: ?PulsePlace, 1: int}}
     */
    private function needOffer(UserContext $ctx, array $topics, float $quality, string $template = 'You need %s, they offer %s.'): array
    {
        $best = null;

        foreach ($ctx->needs as $need) {
            $needTopics = $this->cleanTopics($ctx, $need['topics']);
            [$hits, $cov] = $this->coverage($needTopics, $topics);

            if ($hits !== [] && ($best === null || $cov > $best['cov'])) {
                $best = ['cov' => $cov, 'hits' => $hits, 'need' => $need];
            }
        }

        if ($best === null) {
            return [null, null];
        }

        $weight = self::W_NEED_OFFER * $best['cov'] + 8 * $quality;
        $labels = $this->labels($best['hits']);

        return [
            $this->reason('need_offer', sprintf($template, $labels, $labels), $weight, $best['hits'][0]),
            [$best['need']['place'] ?? $ctx->place, $best['need']['max_rank']],
        ];
    }

    /** Your own offer against their need. */
    private function offerNeed(UserContext $ctx, array $topics, float $quality): ?array
    {
        [$hits, $cov] = $this->coverage($this->cleanTopics($ctx, $ctx->offerTopics), $topics);

        if ($hits === []) {
            return null;
        }

        $labels = $this->labels($hits);

        return $this->reason('offer_need', sprintf('You offer %s, they need %s.', $labels, $labels), self::W_NEED_OFFER * $cov + 8 * $quality, $hits[0]);
    }

    /**
     * Interests, skills, offers and needs against a signal's topics. With $skillsOnly only what you can do counts
     * (used where the sentence says "which you know about").
     *
     * @param  list<string>  $topics
     */
    private function topicReason(UserContext $ctx, array $topics, float $quality, int $max, string $template, bool $skillsOnly = false): ?array
    {
        $mine = $skillsOnly ? array_merge($ctx->skills, $ctx->offerTopics) : $ctx->interestTopics();
        [$hits, $cov] = $this->coverage($this->cleanTopics($ctx, $mine), $topics);

        if ($hits === []) {
            return null;
        }

        return $this->reason('topic', sprintf($template, $this->labels($hits)), $max * $cov + 4 * $quality, $hits[0]);
    }

    /**
     * Your skills against a role's skills, with a nudge when your experience band meets what the role asks.
     *
     * @param  list<string>  $roleSkills
     */
    private function skillRole(UserContext $ctx, PulseSignal $s, array $roleSkills, SignalPack $pack): ?array
    {
        $hits = array_values(array_intersect($this->cleanTopics($ctx, $ctx->skills), $roleSkills));

        if ($hits === []) {
            return null;
        }

        $cov = min(1.0, count($hits) / max(1, min(3, count($roleSkills))));
        $weight = self::W_ROLE * $cov;
        $text = sprintf('Your skills fit this role: %s.', $this->labels($hits));

        $asked = Taxonomy::seniorityBand($pack->roles[(int) $s->subject_id]->seniority ?? null);

        if ($asked !== null && $ctx->experienceBand !== null && Taxonomy::bandRank($ctx->experienceBand) >= Taxonomy::bandRank($asked)) {
            $weight += self::W_EXPERIENCE_FIT;
            $text = rtrim($text, '.').' and your experience meets what it asks for.';
        }

        return $this->reason('skill_role', $text, $weight, $hits[0]);
    }

    /**
     * An employer's roles or hiring skills against a talent signal. Names nobody: skills only.
     *
     * @param  list<string>  $talentSkills
     * @return array{0: ?array<string, mixed>, 1: ?array{0: ?PulsePlace, 1: int}}
     */
    private function roleTalent(UserContext $ctx, PulseSignal $s, array $talentSkills, SignalPack $pack): array
    {
        $best = null;

        foreach ($ctx->seeks as $seek) {
            $hits = array_values(array_intersect($this->cleanTopics($ctx, $seek['skills']), $talentSkills));

            if ($hits === []) {
                continue;
            }

            $cov = min(1.0, count($hits) / max(1, min(3, count($seek['skills']))));

            if ($best === null || $cov > $best['cov']) {
                $best = ['cov' => $cov, 'hits' => $hits, 'seek' => $seek];
            }
        }

        if ($best === null) {
            return [null, null];
        }

        $weight = self::W_ROLE * $best['cov'];
        $text = sprintf('They have skills you are hiring for: %s.', $this->labels($best['hits']));

        $profile = $pack->profileOf($s);
        $band = Taxonomy::experienceBand(isset($profile->experience_years) ? (int) $profile->experience_years : null);

        if ($best['seek']['band'] !== null && $band !== null && Taxonomy::bandRank($band['key']) >= Taxonomy::bandRank($best['seek']['band'])) {
            $weight += self::W_EXPERIENCE_FIT;
            $text = rtrim($text, '.').' and their experience meets what the role asks for.';
        }

        $seek = $best['seek'];

        return [
            $this->reason('role_talent', $text, $weight, $best['hits'][0]),
            [$seek['place'] ?? $ctx->place, $seek['remote'] ? PlaceRepository::RANK_WORLD : PlaceRepository::RANK_COUNTRY],
        ];
    }

    private function openToCollab(UserContext $ctx, ?PulsePlace $sigPlace): ?array
    {
        if (! in_array('collab', $ctx->openTo, true) || $sigPlace === null) {
            return null;
        }

        $rank = $this->places->rank($ctx->place, $sigPlace);

        if ($rank === null || $rank > PlaceRepository::RANK_REGION) {
            return null;
        }

        return $this->reason('topic', 'They are looking for a collaborator nearby, and you said you are open to collaborating.', 12.0);
    }

    private function followsReason(UserContext $ctx, PulseSignal $s, SignalPack $pack): ?array
    {
        if ($s->anonymous) {
            return null;
        }

        if ($s->entity_id !== null && isset($ctx->followedEntityIds[(int) $s->entity_id])) {
            $name = $pack->entities[(int) $s->entity_id]->name ?? 'a business you follow';

            return $this->reason('follows', 'You follow '.$name.'.', self::W_FOLLOWS, (string) $s->entity_id);
        }

        if ($s->owner_id !== null && isset($ctx->followedUserIds[(int) $s->owner_id])) {
            $name = $pack->names[(int) $s->owner_id] ?? 'someone you follow';

            return $this->reason('follows', 'You follow '.$name.'.', self::W_FOLLOWS, 'user:'.$s->owner_id);
        }

        return null;
    }

    private function placeReason(int $rank, PulsePlace $place): array
    {
        $label = $this->places->label($place);
        $where = [0 => 'in your city', 1 => 'in your region', 2 => 'in your country'][$rank];
        $code = [0 => 'place_city', 1 => 'place_region', 2 => 'place_country'][$rank];

        return $this->reason($code, sprintf('They are in %s, %s.', $label, $where), self::W_PLACE[$rank], (string) $place->id);
    }

    private function freshReason(PulseSignal $s): ?array
    {
        $days = $s->published_at->diffInDays(now(), false);

        if ($days < 0 || $days >= 14) {
            return null;
        }

        $text = $days < 1 ? 'Posted today.' : ($days < 7 ? 'Posted this week.' : 'Posted in the last two weeks.');

        return $this->reason('fresh', $text, self::W_FRESH * (1 - $days / 14));
    }

    private function sharesCommunity(UserContext $ctx, PulseSignal $s, SignalPack $pack): bool
    {
        return $ctx->communityIds !== []
            && array_intersect_key($ctx->communityIds, $pack->ownerCommunities[(int) $s->owner_id] ?? []) !== [];
    }

    // -- small helpers ------------------------------------------------------------------------------------------

    /** @return array{code: string, text: string, weight: float, ref: ?string} */
    private function reason(string $code, string $text, float $weight, ?string $ref = null): array
    {
        return ['code' => $code, 'text' => $text, 'weight' => round($weight, 1), 'ref' => $ref];
    }

    /** @param list<array<string, mixed>> $reasons */
    private function has(array $reasons, string $code): bool
    {
        return in_array($code, array_column($reasons, 'code'), true);
    }

    /**
     * @param  list<array<string, mixed>>  $reasons
     * @param  list<string>  $codes
     */
    private function hasAny(array $reasons, array $codes): bool
    {
        return array_intersect($codes, array_column($reasons, 'code')) !== [];
    }

    /**
     * Overlap between two topic sets: the shared slugs and the share of the smaller set they cover.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return array{0: list<string>, 1: float}
     */
    private function coverage(array $a, array $b): array
    {
        if ($a === [] || $b === []) {
            return [[], 0.0];
        }

        $hits = array_values(array_intersect($a, $b));

        return [$hits, count($hits) / min(count(array_unique($a)), count(array_unique($b)))];
    }

    /**
     * @param  list<string>  $slugs
     * @return list<string>
     */
    private function cleanTopics(UserContext $ctx, array $slugs): array
    {
        return array_values(array_filter(array_unique($slugs), fn ($t) => $t !== '' && ! isset($ctx->mutedTopics[$t])));
    }

    /** @param list<string> $slugs */
    private function labels(array $slugs): string
    {
        $labels = array_map(fn ($s) => mb_strtolower(Taxonomy::label($s)), array_slice($slugs, 0, 3));

        return count($labels) > 1
            ? implode(', ', array_slice($labels, 0, -1)).' and '.end($labels)
            : ($labels[0] ?? '');
    }

    // -- candidates and persistence -----------------------------------------------------------------------------

    /** @return Collection<int, PulseSignal> */
    private function candidates(UserContext $ctx): Collection
    {
        $q = PulseSignal::query()
            ->where('status', 'live')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where('published_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->where(fn ($w) => $w->whereNull('owner_id')->orWhere('owner_id', '!=', $ctx->userId));

        if ($ctx->ownEntityIds !== []) {
            $q->where(fn ($w) => $w->whereNull('entity_id')->orWhereNotIn('entity_id', array_keys($ctx->ownEntityIds)));
        }

        // Narrow by the declared hierarchy where the person has declared a place: anything inside reach, plus
        // anything national or global, remote, or placeless. Followed businesses and people always come through.
        if ($ctx->place !== null) {
            $ids = $this->places->flatNearbyIds($ctx->place, 'country');
            $follows = array_keys($ctx->followedEntityIds);
            $followedUsers = array_keys($ctx->followedUserIds);

            $q->where(function ($w) use ($ids, $follows, $followedUsers) {
                $w->whereNull('place_id')->orWhereIn('place_id', $ids)->orWhere('is_remote', true)->orWhereIn('reach', ['national', 'global']);
                if ($follows !== []) {
                    $w->orWhereIn('entity_id', $follows);
                }
                if ($followedUsers !== []) {
                    $w->orWhereIn('owner_id', $followedUsers);
                }
            });
        }

        return $q->orderByDesc('published_at')->limit(600)->get();
    }

    /**
     * @param  Collection<int, PulseSignal>  $signals
     */
    private function prepareNames(UserContext $ctx, Collection $signals, SignalPack $pack): void
    {
        $pack->loadNames($signals->pluck('owner_id')->filter(fn ($id) => isset($ctx->followedUserIds[(int) $id]))->all());
    }

    /**
     * People who might care about a signal: those who declared a place it can reach, plus the followers of its
     * business or author. Scoring then decides; this only bounds the work.
     *
     * @return list<int>
     */
    private function interestedUserIds(PulseSignal $signal): array
    {
        $reachRank = $this->contexts->reachRank((string) $signal->reach);
        $profiles = DB::table('pulse_profiles')->select('user_id');

        if (! $signal->is_remote && $reachRank <= PlaceRepository::RANK_REGION) {
            $place = $signal->place_id ? PulsePlace::find($signal->place_id) : null;

            if ($place === null) {
                $profiles->whereRaw('1 = 0');
            } else {
                $profiles->whereIn('place_id', $this->places->flatNearbyIds($place, $reachRank === 0 ? 'city' : 'region'));
            }
        }

        $ids = $profiles->limit(3000)->pluck('user_id')->map(fn ($v) => (int) $v)->all();

        if ($signal->entity_id !== null) {
            $ids = array_merge($ids, DB::table('pulse_entity_followers')->where('entity_id', $signal->entity_id)->pluck('user_id')->map(fn ($v) => (int) $v)->all());
        }

        if ($signal->owner_id !== null && ! $signal->anonymous) {
            $ids = array_merge($ids, DB::table('pulse_followers')->where('following_id', $signal->owner_id)->pluck('follower_id')->map(fn ($v) => (int) $v)->all());
        }

        // Employers looking for talent, and people with matching needs or offers, are reached through their profile;
        // anyone without one has never opened Pulse and has nothing to match yet.
        return array_values(array_unique($ids));
    }

    /**
     * Upsert match rows without touching seen_at or dismissal. With $replace the person's other undismissed rows
     * that are no longer matches are removed.
     *
     * @param  array<int, array{score: float, reasons: list<array<string, mixed>>}>  $scored  signal id => match
     */
    private function persist(int $userId, array $scored, bool $replace): int
    {
        $now = now();
        $rows = [];

        foreach ($scored as $signalId => $m) {
            $rows[] = [
                'user_id' => $userId,
                'signal_id' => $signalId,
                'score' => $m['score'],
                'reasons' => json_encode($m['reasons'], JSON_UNESCAPED_UNICODE),
                'computed_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('pulse_matches')->upsert($chunk, ['user_id', 'signal_id'], ['score', 'reasons', 'computed_at']);
        }

        if ($replace) {
            $q = DB::table('pulse_matches')->where('user_id', $userId)->whereNull('dismissed_at');

            if ($scored !== []) {
                $q->whereNotIn('signal_id', array_keys($scored));
            }

            $q->delete();
        }

        return count($rows);
    }
}
