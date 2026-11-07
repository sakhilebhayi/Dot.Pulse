<?php

namespace App\Services\Pulse\Discovery;

use App\Models\PulseMatch;
use App\Models\PulsePlace;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\Places\PlaceRepository;
use App\Services\Pulse\Discovery\Support\Arr;
use App\Services\Pulse\Discovery\Support\SubjectTypes;
use Illuminate\Support\Collection;
use App\Support\PulseThrottle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Search over live signals and business pages. Portable SQL (LOWER + LIKE) with token scoring done in PHP, no
 * full-text or vector index. Every result says why it matched in plain words. Held, removed, expired and paused
 * things never appear; nor does anyone the searcher blocked or who blocked them; and anonymous professionals are
 * matched on their headline and skills only, never on a name, and are shown only to employers.
 *
 * Scoring (per matched word): title 10, all words in the title +8, title starts with a word +4, exact phrase in the
 * title +8, topic or skill 6, business name 8, description 3; plus listing quality x3, freshness up to 2, and
 * closeness to the searcher's declared place (city 3, region 2, country 1).
 */
class SearchService
{
    public const PAGE = 20;

    public const CANDIDATES = 300;

    public function __construct(
        private readonly ContextLoader $contexts,
        private readonly Matcher $matcher,
        private readonly BeatBuilder $beats,
        private readonly PlaceRepository $places,
    ) {}

    /**
     * Filters: kind (string|list), mode (trade|work|local|learn), place (slug or id: that place and its region),
     * remote (bool), price_min / price_max (rand, offers only), employment_type (string|list, roles only).
     *
     * @param  array<string, mixed>  $filters
     * @return array{results: list<array<string, mixed>>, next: ?string, total: int, q: string}
     */
    public function search(User $u, string $q, array $filters = [], ?string $cursor = null): array
    {
        $this->throttle($u, 'search');

        $q = trim(mb_substr($q, 0, 120));
        $terms = $this->terms($q);
        $hasFilter = array_filter(Arr::only($filters), fn ($v) => $v !== null && $v !== '' && $v !== []) !== [];
        $offset = $this->decode($cursor);

        if ($terms === [] && ! $hasFilter) {
            return ['results' => [], 'next' => null, 'total' => 0, 'q' => $q];
        }

        $ctx = $this->contexts->loadExclusions($u->id);
        $viewerPlace = $this->places->forUser($u);

        $signals = $this->signalCandidates($u, $ctx, $terms, $filters);
        $pack = SignalPack::build($signals);
        $results = [];

        foreach ($signals as $signal) {
            if (! $this->matcher->eligible($ctx, $signal, $pack)) {
                continue;
            }

            [$score, $why] = $this->scoreSignal($signal, $terms, $q, $pack, $viewerPlace);

            if ($terms !== [] && $score <= 0) {
                continue;
            }

            $results[] = ['type' => 'signal', 'score' => $score, 'why' => $why, 'signal' => $signal, 'published' => $signal->published_at?->timestamp ?? 0];
        }

        if ($this->wantsEntities($filters)) {
            foreach ($this->entityResults($ctx, $terms, $q, $viewerPlace) as $r) {
                $results[] = $r;
            }
        }

        usort($results, fn ($a, $b) => [$b['score'], $b['published'] ?? 0] <=> [$a['score'], $a['published'] ?? 0]);

        $total = count($results);
        $page = array_slice($results, $offset, self::PAGE);

        return [
            'results' => $this->present($u, $page, $pack),
            'next' => ($offset + self::PAGE) < $total ? $this->encode($offset + self::PAGE) : null,
            'total' => $total,
            'q' => $q,
        ];
    }

    /**
     * Typed-ahead: curated topics and skills, then business names, then live listing titles.
     *
     * @return list<array{type: string, label: string, value: string}>
     */
    public function suggest(User $u, string $q, int $limit = 8): array
    {
        $q = trim($q);

        if (mb_strlen($q) >= 2) {
            $this->throttle($u, 'suggest');
        }

        if (mb_strlen($q) < 2) {
            return [];
        }

        $out = [];

        foreach ((new Taxonomy)->suggest($q, 'any', 4) as $t) {
            $out[] = ['type' => $t['kind'], 'label' => $t['label'], 'value' => $t['label']];
        }

        $ctx = $this->contexts->loadExclusions($u->id);
        $like = $this->like(Str::lower($q)).'%';

        $entities = DB::table('pulse_entities')->where('status', 'live')->whereNull('deleted_at')
            ->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])->limit(5)->get(['id', 'name', 'slug', 'created_by']);

        foreach ($entities as $e) {
            if (! isset($ctx->blockedUserIds[(int) $e->created_by])) {
                $out[] = ['type' => 'business', 'label' => $e->name, 'value' => $e->name];
            }
        }

        $titles = PulseSignal::query()->where('status', 'live')->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where('kind', '!=', 'talent')->where('anonymous', false)
            ->whereRaw("LOWER(title) LIKE ? ESCAPE '\\'", [$like])->orderByDesc('published_at')->limit(8)->get();
        $pack = SignalPack::build($titles);

        foreach ($titles as $s) {
            if ($this->matcher->eligible($ctx, $s, $pack)) {
                $out[] = ['type' => 'listing', 'label' => $s->title, 'value' => $s->title];
            }
        }

        $seen = [];

        return array_slice(array_values(array_filter($out, function ($row) use (&$seen) {
            $key = Str::lower($row['label']);

            return isset($seen[$key]) ? false : ($seen[$key] = true);
        })), 0, $limit);
    }

    /** Per person per minute, from config('pulse.security.rate_limits'); a refusal is the same 429 the other limits give. */
    private function throttle(User $u, string $action): void
    {
        $max = (int) config("pulse.security.rate_limits.{$action}", 0);

        if ($max <= 0) {
            return;
        }

        $key = "pulse:{$action}:".$u->id;

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw PulseThrottle::exception(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, PulseThrottle::WINDOW_SECONDS);
    }

    // -- candidates ---------------------------------------------------------------------------------------------

    /**
     * @param  list<string>  $terms
     * @param  array<string, mixed>  $filters
     * @return Collection<int, PulseSignal>
     */
    private function signalCandidates(User $u, UserContext $ctx, array $terms, array $filters): Collection
    {
        $query = PulseSignal::query()
            ->where('status', 'live')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));

        // Anonymous professionals are for employers only.
        if (! $ctx->isEmployer) {
            $query->where('kind', '!=', 'talent')->where('anonymous', false);
        }

        if ($terms !== []) {
            $query->where(function ($w) use ($terms) {
                foreach ($terms as $t) {
                    $like = '%'.$this->like($t).'%';
                    $w->orWhereRaw("LOWER(title) LIKE ? ESCAPE '\\'", [$like])
                        ->orWhereRaw("LOWER(COALESCE(excerpt, '')) LIKE ? ESCAPE '\\'", [$like])
                        ->orWhereRaw("LOWER(CAST(topics AS TEXT)) LIKE ? ESCAPE '\\'", [$like])
                        ->orWhereRaw("LOWER(CAST(skills AS TEXT)) LIKE ? ESCAPE '\\'", [$like])
                        ->orWhereIn('entity_id', fn ($sub) => $sub->select('id')->from('pulse_entities')->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like]));
                }
            });
        }

        $kinds = Arr::kinds($filters['kind'] ?? null);

        if (($mode = $filters['mode'] ?? null) && in_array($mode, ['trade', 'work', 'learn'], true)) {
            $modeKinds = Taxonomy::kindsOfMode($mode) ?? [];
            $kinds = $kinds === [] ? $modeKinds : array_values(array_intersect($kinds, $modeKinds));
            $kinds = $kinds === [] ? ['__none__'] : $kinds;
        }

        if ($kinds !== []) {
            $query->whereIn('kind', $kinds);
        }

        $placeFilter = $filters['place'] ?? null;
        if (($filters['mode'] ?? null) === 'local' && $placeFilter === null && ($mine = $this->places->forUser($u))) {
            $placeFilter = $mine->id;
        }

        if ($placeFilter !== null && $placeFilter !== '') {
            $place = is_numeric($placeFilter) ? $this->places->find((int) $placeFilter) : $this->places->findBySlug((string) $placeFilter);
            $query->whereIn('place_id', $place ? $this->places->flatNearbyIds($place, 'region') : [0]);
        }

        if (! empty($filters['remote'])) {
            $query->where('is_remote', true);
        }

        $min = isset($filters['price_min']) && $filters['price_min'] !== '' ? (int) round((float) $filters['price_min'] * 100) : null;
        $max = isset($filters['price_max']) && $filters['price_max'] !== '' ? (int) round((float) $filters['price_max'] * 100) : null;

        if ($min !== null || $max !== null) {
            $query->whereIn('subject_type', SubjectTypes::variants('offer'))
                ->whereIn('subject_id', function ($sub) use ($min, $max) {
                    $sub->select('id')->from('pulse_offers')->whereNotNull('amount_cents')
                        ->when($min !== null, fn ($q) => $q->where('amount_cents', '>=', $min))
                        ->when($max !== null, fn ($q) => $q->where('amount_cents', '<=', $max));
                });
        }

        $types = Arr::kinds($filters['employment_type'] ?? null);
        if ($types !== []) {
            $query->whereIn('subject_type', SubjectTypes::variants('role'))
                ->whereIn('subject_id', fn ($sub) => $sub->select('id')->from('pulse_roles')->whereIn('employment_type', $types));
        }

        return $query->orderByDesc('published_at')->limit(self::CANDIDATES)->get();
    }

    /**
     * @param  list<string>  $terms
     * @return array{0: float, 1: list<string>}
     */
    private function scoreSignal(PulseSignal $s, array $terms, string $q, SignalPack $pack, ?PulsePlace $viewerPlace): array
    {
        $title = Str::lower($s->title);
        $excerpt = Str::lower((string) $s->excerpt);
        $entityName = Str::lower((string) ($pack->entities[(int) $s->entity_id]->name ?? ''));
        $slugs = array_merge((array) $s->topics, (array) $s->skills);
        $score = 0.0;
        $why = [];
        $inTitle = 0;

        foreach ($terms as $t) {
            $slug = Str::slug($t);

            if (str_contains($title, $t)) {
                $score += 10;
                $inTitle++;
                $why['title'] = 'Matched your words in the title.';
                if (str_starts_with($title, $t)) {
                    $score += 4;
                }
            }

            foreach ($slugs as $sl) {
                if ($slug !== '' && (str_contains((string) $sl, $slug))) {
                    $score += 6;
                    $why['topic:'.$sl] = 'Matches the topic '.mb_strtolower(Taxonomy::label((string) $sl)).'.';
                    break;
                }
            }

            if ($entityName !== '' && str_contains($entityName, $t)) {
                $score += 8;
                $why['entity'] = 'Matched the business name.';
            }

            if ($excerpt !== '' && str_contains($excerpt, $t)) {
                $score += 3;
                $why['excerpt'] = 'Matched your words in the description.';
            }
        }

        if ($terms !== [] && $inTitle === count($terms)) {
            $score += 8;
        }

        if (mb_strlen($q) >= 3 && str_contains($title, Str::lower($q))) {
            $score += 8;
            $why['title'] = 'Matched your words in the title.';
        }

        if ($score > 0) {
            $score += 3 * max(0.0, min(1.0, (float) $s->quality));

            if ($s->published_at !== null) {
                $score += 2 * max(0.0, 1 - $s->published_at->diffInDays(now()) / 30);
            }

            $rank = $this->places->rank($viewerPlace, $pack->place($s->place_id));

            if ($rank !== null && $rank <= 2) {
                $score += [3, 2, 1][$rank];
                $why['place'] = ['They are in your city.', 'They are in your region.', 'They are in your country.'][$rank];
            }
        }

        return [round($score, 2), array_values($why)];
    }

    /**
     * @param  list<string>  $terms
     * @return list<array<string, mixed>>
     */
    private function entityResults(UserContext $ctx, array $terms, string $q, ?PulsePlace $viewerPlace): array
    {
        if ($terms === []) {
            return [];
        }

        $rows = DB::table('pulse_entities')->where('status', 'live')->whereNull('deleted_at')
            ->where(function ($w) use ($terms) {
                foreach ($terms as $t) {
                    $like = '%'.$this->like($t).'%';
                    $w->orWhereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])
                        ->orWhereRaw("LOWER(COALESCE(tagline, '')) LIKE ? ESCAPE '\\'", [$like])
                        ->orWhereRaw("LOWER(COALESCE(industry, '')) LIKE ? ESCAPE '\\'", [$like]);
                }
            })
            ->limit(60)
            ->get(['id', 'slug', 'name', 'tagline', 'industry', 'place_id', 'created_by', 'verification_status', 'verification_source', 'verified_at', 'registration_number', 'logo_media_id']);

        $owners = DB::table('pulse_entity_members')->whereIn('entity_id', $rows->pluck('id')->all() ?: [0])->where('role', 'owner')->get(['entity_id', 'user_id'])->groupBy('entity_id');
        $placeMap = PulsePlace::whereIn('id', $rows->pluck('place_id')->filter()->all() ?: [0])->get()->keyBy('id');
        $out = [];

        foreach ($rows as $e) {
            $ownerIds = array_merge([(int) $e->created_by], ($owners->get($e->id) ?? collect())->pluck('user_id')->map(fn ($v) => (int) $v)->all());

            if (isset($ctx->ownEntityIds[(int) $e->id]) || array_intersect_key(array_flip($ownerIds), $ctx->blockedUserIds) !== []
                || isset($ctx->mutedEntities[(string) $e->id]) || isset($ctx->mutedEntities[(string) $e->slug])) {
                continue;
            }

            $name = Str::lower($e->name);
            $tag = Str::lower((string) $e->tagline.' '.(string) $e->industry);
            $score = 0.0;
            $why = [];

            foreach ($terms as $t) {
                if (str_contains($name, $t)) {
                    $score += 12;
                    $why['name'] = 'Matched the business name.';
                }
                if (str_contains($tag, $t)) {
                    $score += 5;
                    $why['tagline'] = 'Matched what the business says it does.';
                }
            }

            if ($score <= 0) {
                continue;
            }

            if (str_starts_with($name, Str::lower($q))) {
                $score += 4;
            }

            $place = $e->place_id ? $placeMap->get($e->place_id) : null;
            $rank = $this->places->rank($viewerPlace, $place);

            if ($rank !== null && $rank <= 2) {
                $score += [3, 2, 1][$rank];
            }

            $out[] = [
                'type' => 'entity',
                'score' => round($score, 2),
                'why' => array_values($why),
                'published' => 0,
                'entity' => [
                    'id' => (int) $e->id,
                    'slug' => $e->slug,
                    'name' => $e->name,
                    'tagline' => $e->tagline,
                    'industry' => $e->industry,
                    'place' => $place ? $this->places->label($place) : null,
                    'logo_media_id' => $e->logo_media_id,
                    'verification' => $e->verification_source === 'infodot' && $e->verified_at ? 'Checked through InfoDot' : ($e->registration_number ? 'Claims a registration number. Not checked.' : null),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $page
     * @return list<array<string, mixed>>
     */
    private function present(User $u, array $page, SignalPack $pack): array
    {
        $signals = collect($page)->where('type', 'signal')->pluck('signal');
        $matches = [];

        if ($signals->isNotEmpty()) {
            foreach (PulseMatch::where('user_id', $u->id)->whereIn('signal_id', $signals->pluck('id')->all())->get() as $m) {
                $matches[$m->signal_id] = ['id' => $m->id, 'score' => $m->score, 'reasons' => (array) $m->reasons];
            }
        }

        $saved = DB::table('pulse_saves')->where('user_id', $u->id)->where('saveable_type', 'signal')
            ->whereIn('saveable_id', $signals->pluck('id')->all() ?: [0])->pluck('saveable_id')->flip()->map(fn () => true)->all();

        $beats = $this->beats->build($u->id, $signals->values(), $pack, $matches, $saved)->keyBy(fn (Beat $b) => $b->signal->id);

        return array_map(function (array $r) use ($beats) {
            if ($r['type'] === 'signal') {
                return ['type' => 'signal', 'score' => $r['score'], 'why' => $r['why'], 'beat' => $beats->get($r['signal']->id), 'entity' => null];
            }

            return ['type' => 'entity', 'score' => $r['score'], 'why' => $r['why'], 'beat' => null, 'entity' => $r['entity']];
        }, $page);
    }

    /** @param array<string, mixed> $filters */
    private function wantsEntities(array $filters): bool
    {
        if (! empty($filters['remote']) || ! empty($filters['price_min']) || ! empty($filters['price_max']) || ! empty($filters['employment_type'])) {
            return false;
        }

        $kinds = Arr::kinds($filters['kind'] ?? null);

        return ($kinds === [] || in_array('business', $kinds, true)) && ! in_array($filters['mode'] ?? null, ['work', 'learn'], true);
    }

    /**
     * Lowercased words of 2+ characters plus the canonical taxonomy phrase for each ("plumber" also looks for
     * "plumbing"), at most eight.
     *
     * @return list<string>
     */
    private function terms(string $q): array
    {
        $words = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', Str::lower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn ($w) => mb_strlen($w) >= 2,
        )));

        $terms = $words;

        foreach (Taxonomy::extract($q) as $slug) {
            $label = Str::lower(Taxonomy::label($slug));

            if (! in_array($label, $terms, true) && ! in_array($label, $words, true)) {
                $terms[] = $label;
            }
        }

        return array_slice(array_values(array_unique($terms)), 0, 8);
    }

    private function like(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    private function encode(int $offset): string
    {
        return rtrim(strtr(base64_encode('o:'.$offset), '+/', '-_'), '=');
    }

    private function decode(?string $cursor): int
    {
        if (! $cursor) {
            return 0;
        }

        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);

        return ($raw !== false && preg_match('/^o:(\d{1,6})$/', $raw, $m)) ? (int) $m[1] : 0;
    }
}
