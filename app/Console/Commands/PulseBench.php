<?php

namespace App\Console\Commands;

use App\Models\PulseConversation;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\Digest\DigestBuilder;
use App\Services\Pulse\Discovery\Floor;
use App\Services\Pulse\Discovery\Matcher;
use App\Services\Pulse\Discovery\SearchService;
use App\Services\Pulse\Discovery\Taxonomy;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Operator\Metrics;
use Database\Seeders\PulsePlaceSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds a deterministic, production-shaped Dot.Pulse dataset into a THROWAWAY database and times the hot paths.
 *
 *   createdb pulse_perf && DB_DATABASE=pulse_perf php artisan migrate && DB_DATABASE=pulse_perf php artisan pulse:bench --seed
 *
 * It refuses to run unless APP_ENV is local or testing (or --force-i-know), and refuses a database whose name does
 * not contain "perf" or "bench" (or an in-memory SQLite) unless --force-i-know is given as well, because --seed
 * wipes every pulse_* table and every user. Never point it at a real database.
 *
 * Direct inserts in chunks (explicit ids, so no round trips for keys), a fixed random seed: two runs seed the same
 * rows. Timings are p50/p95 over repeated runs; query counts come from DB::listen; memory is the PHP peak.
 */
class PulseBench extends Command
{
    protected $signature = 'pulse:bench
        {--seed : Wipe the pulse tables and seed the synthetic dataset first}
        {--scale=1 : Multiply the default dataset size (0.1 is a quick smoke run)}
        {--runs=15 : Repeats per scenario}
        {--only= : Comma list of scenario prefixes to run (floor, search, matcher, match, index, digest, inbox, thread, operator, http)}
        {--full-match : Also run pulse:match --all over every profile (slow)}
        {--json= : Write the results to this file}
        {--force-i-know : Run outside local/testing, or against a database not named perf/bench}';

    protected $description = 'Seed a production-shaped synthetic Dot.Pulse dataset and time the hot paths (throwaway databases only)';

    /** Default dataset size at --scale=1. */
    private const SIZE = [
        'users' => 20000, 'entities' => 4000, 'offers' => 12000, 'roles' => 4000, 'needs' => 8000, 'posts' => 3000,
        'matches' => 100000, 'conversations' => 60000, 'messages' => 400000, 'saves' => 30000,
        'entity_followers' => 40000, 'user_followers' => 30000, 'mutes' => 5000, 'blocks' => 2000,
        'prefs' => 5000, 'notifications' => 40000, 'audit' => 20000, 'reports' => 600,
    ];

    /** Targets in milliseconds (memory in MB) the run is judged against. */
    private const TARGETS = [
        'floor.first' => 250, 'floor.later' => 250,
        'search.one_word' => 300, 'search.three_words' => 300, 'search.filtered' => 300,
        'inbox.requests' => 150, 'inbox.open' => 150, 'inbox.sent' => 150, 'inbox.closed' => 150, 'inbox.ignored' => 150, 'inbox.all' => 150,
        'inbox.heavy.open' => 150, 'inbox.heavy.all' => 150,
        'thread.typical' => 150, 'thread.heavy' => 150,
        'operator.snapshot.cold' => 1500,
        'http.pulse_check' => 30,
        'match.cycle' => 300000, 'index.rebuild' => 180000,
    ];

    /** @var array<string, array<string, mixed>> */
    private array $results = [];

    /** @var list<array{string, float}> */
    private array $queryLog = [];

    private int $n = 0;

    private float $scale = 1.0;

    /** @var array<string, int> */
    private array $count = [];

    public function handle(): int
    {
        if (! in_array(app()->environment(), ['local', 'testing'], true) && ! $this->option('force-i-know')) {
            $this->error('pulse:bench only runs when APP_ENV is local or testing (or with --force-i-know).');

            return self::FAILURE;
        }

        $db = (string) DB::connection()->getDatabaseName();
        $throwaway = $db === ':memory:' || preg_match('/perf|bench/i', $db) === 1;

        if (! $throwaway && ! $this->option('force-i-know')) {
            $this->error("Refusing to use database '{$db}': --seed wipes all pulse tables and users. Use a database named like pulse_perf.");

            return self::FAILURE;
        }

        $this->scale = max(0.01, (float) $this->option('scale'));
        foreach (self::SIZE as $k => $v) {
            $this->count[$k] = max(10, (int) round($v * $this->scale));
        }

        ini_set('memory_limit', '2G');
        config(['app.debug' => false, 'session.driver' => 'array']);
        DB::connection()->disableQueryLog();
        DB::listen(function ($q) {
            $this->n++;
            if (count($this->queryLog) < 5000) {
                $this->queryLog[] = [$q->sql, $q->time];
            }
        });

        $this->info(sprintf('Driver %s, database %s, scale %s', DB::connection()->getDriverName(), $db, $this->scale));

        if ($this->option('seed')) {
            $t = microtime(true);
            $this->seed();
            $this->info(sprintf('Seeded in %.1f s', microtime(true) - $t));
        }

        if (! DB::table('pulse_signals')->exists()) {
            $this->error('The database holds no dataset. Run with --seed first.');

            return self::FAILURE;
        }

        $this->analyse(); // the planner needs honest statistics on both engines, or the timings measure a bad plan
        $this->printCounts();
        $this->runScenarios();
        $this->report();

        return self::SUCCESS;
    }

    // ================================================================================================ seeding

    private function seed(): void
    {
        mt_srand(4141);
        $now = time();
        $stamp = fn (int $ts) => date('Y-m-d H:i:s', $ts);
        $c = $this->count;

        $this->wipe();

        if (! DB::table('pulse_places')->exists()) {
            app(PulsePlaceSeeder::class)->run();
        }

        $cities = DB::table('pulse_places')->where('level', 'city')->where('country_code', 'ZA')->orderBy('id')->pluck('id')->all();
        $cityOf = fn () => $cities[(int) (count($cities) * (mt_rand() / mt_getrandmax()) ** 2.2)];
        $topics = array_column((new Taxonomy)->topics(), 'slug');
        $skills = array_column((new Taxonomy)->skills(), 'slug');
        $topicOf = fn () => $topics[(int) (count($topics) * (mt_rand() / mt_getrandmax()) ** 1.8)];
        $skillOf = fn () => $skills[(int) (count($skills) * (mt_rand() / mt_getrandmax()) ** 1.8)];
        $words = ['Bright', 'Prime', 'Ubuntu', 'Karoo', 'Table', 'Summit', 'Proud', 'Swift', 'Delta', 'Metro', 'Coastal', 'Highveld', 'Apex', 'Nova', 'Sable', 'Eagle'];
        $label = fn (string $slug) => ucwords(str_replace('-', ' ', $slug));
        $hash = Hash::make('password');
        $rand = fn () => mt_rand() / mt_getrandmax();
        $past = fn (int $days) => $stamp($now - (int) ($rand() * $days * 86400));

        // ---- users and profiles -------------------------------------------------------------------------
        $this->step('users', $c['users']);
        $this->bulk('users', $this->gen($c['users'], fn ($i) => [
            'id' => $i, 'name' => 'Bench User '.$i, 'email' => "bench-{$i}@perf.test", 'email_verified_at' => $stamp($now - 86400),
            'password' => $hash, 'created_at' => $past(300), 'updated_at' => $stamp($now),
        ]));

        $this->step('profiles', $c['users']);
        $discover = [];
        $this->bulk('pulse_profiles', $this->gen($c['users'], function ($i) use (&$discover, $cityOf, $skillOf, $topicOf, $rand, $stamp, $now, $label) {
            $pro = $rand() < 0.25;
            $disc = $pro ? ($rand() < 0.5 ? 'open' : ($rand() < 0.6 ? 'anonymous' : 'off')) : 'off';
            $adult = $rand() < 0.85;
            if ($disc !== 'off' && $adult) {
                $discover[$i] = $disc;
            }
            $s = [$skillOf(), $skillOf(), $skillOf()];

            return [
                'id' => $i, 'user_id' => $i, 'headline' => $label($s[0]).' specialist', 'bio' => 'Bench bio '.$i.' '.str_repeat('about work and trade. ', 3),
                'skills' => json_encode($s), 'expertise_tags' => json_encode([$topicOf()]), 'interests' => json_encode([$topicOf(), $topicOf()]),
                'open_to' => json_encode($pro ? ['work', 'freelance'] : ['collab']), 'kind' => $pro ? 'professional' : 'individual',
                'discoverability' => $disc, 'place_id' => $rand() < 0.95 ? $cityOf() : null, 'reach' => ['city', 'region', 'region', 'country', 'remote'][mt_rand(0, 4)],
                'show_place' => 1, 'experience_years' => mt_rand(0, 25), 'contact_policy' => 'context', 'onboarded_at' => $stamp($now - 86400 * 20),
                'age_confirmed_at' => $adult ? $stamp($now - 86400 * 20) : null, 'moderation' => 'approved',
                'role' => $i === 1 ? 'moderator' : 'customer', 'is_verified' => 0, 'community_points' => 0, 'solutions_accepted' => 0,
                'created_at' => $stamp($now - 86400 * 20), 'updated_at' => $stamp($now - 86400 * 20),
            ];
        }));

        // ---- business pages ----------------------------------------------------------------------------
        $this->step('entities', $c['entities']);
        $entityPlace = [];
        $liveEntities = [];
        $this->bulk('pulse_entities', $this->gen($c['entities'], function ($i) use (&$entityPlace, &$liveEntities, $c, $cityOf, $topicOf, $words, $label, $rand, $past) {
            $r = $rand();
            $status = $r < 0.92 ? 'live' : ($r < 0.97 ? 'draft' : 'suspended');
            $topic = $topicOf();
            $place = $cityOf();
            $entityPlace[$i] = $place;
            $status === 'live' && $liveEntities[] = $i;
            $owner = $i <= 15 ? (($i - 1) % 5) + 2 : mt_rand(1, $c['users']);

            return [
                'id' => $i, 'slug' => 'biz-'.$i, 'name' => $words[$i % count($words)].' '.$label($topic).' '.$i, 'tagline' => 'We do '.str_replace('-', ' ', $topic),
                'about' => 'Bench about '.$i, 'is_seller' => $rand() < 0.75 ? 1 : 0, 'is_employer' => $rand() < 0.4 ? 1 : 0, 'industry' => $topic,
                'place_id' => $place, 'verification_status' => $rand() < 0.3 ? 'claimed' : 'unverified', 'status' => $status, 'moderation' => 'approved',
                'contact_policy' => 'anyone', 'hiring_signal' => 'none', 'created_by' => $owner, 'followers_count' => 0,
                'created_at' => $past(200), 'updated_at' => $past(60),
            ];
        }));

        $members = [];
        for ($i = 1; $i <= $c['entities']; $i++) {
            $owner = $i <= 15 ? (($i - 1) % 5) + 2 : null;
            $members[] = ['id' => $i, 'entity_id' => $i, 'user_id' => $owner ?? mt_rand(1, $c['users']), 'role' => 'owner', 'created_at' => $stamp($now), 'updated_at' => $stamp($now)];
        }
        $seen = [];
        $id = $c['entities'];
        foreach ($members as $m) {
            $seen[$m['entity_id'].':'.$m['user_id']] = true;
        }
        for ($i = 1; $i <= $c['entities'] * 0.2; $i++) {
            $e = mt_rand(1, $c['entities']);
            $u = mt_rand(1, $c['users']);
            if (isset($seen["$e:$u"])) {
                continue;
            }
            $seen["$e:$u"] = true;
            $members[] = ['id' => ++$id, 'entity_id' => $e, 'user_id' => $u, 'role' => ['admin', 'responder', 'editor'][mt_rand(0, 2)], 'created_at' => $stamp($now), 'updated_at' => $stamp($now)];
        }
        $this->bulk('pulse_entity_members', $members);

        // ---- offers, roles, needs ----------------------------------------------------------------------
        $heavyEntity = fn () => mt_rand(1, 15);
        $pickEntity = fn () => $rand() < 0.12 ? $heavyEntity() : ($liveEntities[mt_rand(0, count($liveEntities) - 1)]);
        $suffix = ['services', 'supply', 'installation', 'repairs', 'consulting', 'training', 'delivery', 'wholesale'];

        $this->step('offers', $c['offers']);
        $offers = [];
        $this->bulk('pulse_offers', $this->gen($c['offers'], function ($i) use (&$offers, $pickEntity, $entityPlace, $topicOf, $label, $suffix, $rand, $stamp, $now) {
            $e = $pickEntity();
            $t1 = $topicOf();
            $pub = $now - (int) ($rand() ** 1.5 * 120 * 86400);
            $r = $rand();
            $status = $r < 0.88 ? 'live' : ($r < 0.94 ? 'paused' : 'draft');
            $offers[$i] = [$e, $t1, $pub, $status];

            return [
                'id' => $i, 'entity_id' => $e, 'created_by' => 1, 'slug' => 'offer-'.$i, 'kind' => $rand() < 0.6 ? 'product' : 'service',
                'title' => $label($t1).' '.$suffix[$i % count($suffix)], 'summary' => 'Quality '.str_replace('-', ' ', $t1).' for homes and businesses.',
                'description' => str_repeat('Bench description for '.$t1.'. ', 8), 'category' => $t1, 'tags' => json_encode([$t1, $topicOf()]),
                'price_mode' => 'fixed', 'amount_cents' => mt_rand(5000, 5000000), 'currency' => 'ZAR', 'place_id' => $entityPlace[$e],
                'availability' => 'available', 'accepts_quotes' => 1, 'status' => $status, 'moderation' => $rand() < 0.97 ? 'approved' : 'held',
                'saves_count' => 0, 'enquiries_count' => 0, 'published_at' => $stamp($pub), 'created_at' => $stamp($pub), 'updated_at' => $stamp($pub),
            ];
        }));

        $this->step('roles', $c['roles']);
        $roles = [];
        $this->bulk('pulse_roles', $this->gen($c['roles'], function ($i) use (&$roles, $pickEntity, $entityPlace, $skillOf, $label, $rand, $stamp, $now) {
            $e = $pickEntity();
            $sk = [$skillOf(), $skillOf(), $skillOf()];
            $pub = $now - (int) ($rand() ** 1.5 * 100 * 86400);
            $status = $rand() < 0.85 ? 'live' : 'closed';
            $roles[$i] = [$e, $sk, $pub, $status];

            return [
                'id' => $i, 'entity_id' => $e, 'created_by' => 1, 'slug' => 'role-'.$i, 'title' => $label($sk[0]).' wanted', 'summary' => 'Join us as '.$sk[0],
                'description' => str_repeat('Bench role text. ', 10), 'employment_type' => ['full_time', 'part_time', 'contract', 'freelance'][mt_rand(0, 3)],
                'work_mode' => ['onsite', 'hybrid', 'remote'][mt_rand(0, 2)], 'seniority' => ['junior', 'mid', 'senior'][mt_rand(0, 2)], 'place_id' => $entityPlace[$e],
                'skills' => json_encode($sk), 'status' => $status, 'moderation' => 'approved', 'apply_mode' => 'on_pulse',
                'published_at' => $stamp($pub), 'created_at' => $stamp($pub), 'updated_at' => $stamp($pub),
            ];
        }));

        $this->step('needs', $c['needs']);
        $needs = [];
        $this->bulk('pulse_needs', $this->gen($c['needs'], function ($i) use (&$needs, $c, $cityOf, $topicOf, $rand, $stamp, $now) {
            $u = $i <= 75 ? (($i - 1) % 5) + 2 : mt_rand(1, $c['users']);
            $t = $topicOf();
            $pub = $now - (int) ($rand() ** 1.5 * 40 * 86400);
            $place = $cityOf();
            $kind = ['need', 'need', 'need', 'supplier', 'collab', 'mentor'][mt_rand(0, 5)];
            $status = $rand() < 0.9 ? 'open' : 'closed';
            $needs[$i] = [$u, $kind, $t, $place, $pub, $status];

            return [
                'id' => $i, 'user_id' => $u, 'kind' => $kind, 'title' => 'Looking for '.str_replace('-', ' ', $t), 'details' => 'Bench need details '.$i.' '.str_repeat('text ', 12),
                'tags' => json_encode([$t]), 'place_id' => $place, 'reach' => ['region', 'city', 'country'][mt_rand(0, 2)], 'visibility' => $rand() < 0.8 ? 'open' : 'matched',
                'status' => $status, 'moderation' => 'approved', 'published_at' => $stamp($pub), 'created_at' => $stamp($pub), 'updated_at' => $stamp($pub),
            ];
        }));

        $this->step('posts', $c['posts']);
        $posts = [];
        $this->bulk('pulse_posts', $this->gen($c['posts'], function ($i) use (&$posts, $c, $topicOf, $label, $rand, $stamp, $now) {
            $t = $topicOf();
            $pub = $now - (int) ($rand() * 60 * 86400);
            $type = ['question', 'event', 'announcement', 'article'][mt_rand(0, 3)];
            $posts[$i] = [$t, $type, $pub];

            return [
                'id' => $i, 'user_id' => mt_rand(1, $c['users']), 'type' => $type, 'title' => $label($t).' '.$type.' '.$i, 'body' => 'Bench post '.str_repeat('body ', 20),
                'status' => 'published', 'is_pinned' => 0, 'views_count' => 0, 'comments_count' => 0, 'reactions_count' => 0, 'ai_relevance_score' => 0.5,
                'created_at' => $stamp($pub), 'updated_at' => $stamp($pub), 'published_at' => $stamp($pub),
            ];
        }));

        // ---- the index (signals) -----------------------------------------------------------------------
        $this->step('signals', 0);
        $sig = [];
        $sid = 0;
        $sigRow = function (string $kind, string $type, int $subject, ?int $owner, ?int $entity, string $title, ?string $excerpt, array $topicList, array $skillList, ?int $place, string $reach, bool $remote, bool $anon, string $status, int $pub) use (&$sid, $rand, $stamp) {
            return [
                'id' => ++$sid, 'kind' => $kind, 'subject_type' => $type, 'subject_id' => $subject, 'owner_id' => $owner, 'entity_id' => $entity,
                'title' => mb_substr($title, 0, 160), 'excerpt' => $excerpt, 'topics' => json_encode($topicList), 'skills' => json_encode($skillList), 'place_id' => $place,
                'reach' => $reach, 'is_remote' => $remote ? 1 : 0, 'anonymous' => $anon ? 1 : 0, 'quality' => round(0.2 + $rand() * 0.8, 2), 'status' => $status,
                'published_at' => $stamp($pub), 'created_at' => $stamp($pub), 'updated_at' => $stamp($pub),
            ];
        };

        foreach ($offers as $i => [$e, $t, $pub, $status]) {
            $sig[] = $sigRow('offer', 'offer', $i, 1, $e, $label($t).' '.$suffix[$i % count($suffix)], 'Quality '.str_replace('-', ' ', $t).' for homes and businesses.', [$t], [], $entityPlace[$e], ['local', 'region', 'region', 'national'][mt_rand(0, 3)], $rand() < 0.05, false, $status === 'live' ? 'live' : 'paused', $pub);
        }
        foreach ($roles as $i => [$e, $sk, $pub, $status]) {
            $sig[] = $sigRow('role', 'role', $i, 1, $e, $label($sk[0]).' wanted', 'Join us as '.$sk[0], [], $sk, $entityPlace[$e], 'region', $rand() < 0.2, false, $status === 'live' ? 'live' : 'expired', $pub);
        }
        foreach ($needs as $i => [$u, $kind, $t, $place, $pub, $status]) {
            $sig[] = $sigRow($kind === 'collab' ? 'collab' : ($kind === 'mentor' ? 'ask' : 'need'), 'need', $i, $u, null, 'Looking for '.str_replace('-', ' ', $t), 'Bench need details '.$i, [$t], [], $place, 'region', false, false, $status === 'open' ? 'live' : 'expired', $pub);
        }
        foreach ($posts as $i => [$t, $type, $pub]) {
            $sig[] = $sigRow($type === 'event' ? 'event' : ($type === 'question' ? 'ask' : 'news'), 'post', $i, null, null, $label($t).' '.$type.' '.$i, null, [$t], [], null, 'national', false, false, 'live', $pub);
        }
        for ($i = 1; $i <= $c['entities']; $i++) {
            if (in_array($i, $liveEntities, true)) {
                $sig[] = $sigRow('business', 'entity', $i, null, $i, 'Business '.$i, null, [], [], $entityPlace[$i], 'region', false, false, 'live', $now - 86400 * 30);
            }
        }
        foreach ($discover as $uid => $disc) {
            $sig[] = $sigRow('talent', 'profile', $uid, $uid, null, 'Specialist '.$uid, null, [], [$skillOf(), $skillOf()], $cityOf(), 'region', false, $disc === 'anonymous', 'live', $now - mt_rand(0, 50) * 86400);
        }
        $this->bulk('pulse_signals', $sig);
        $this->line('  signals: '.count($sig));
        unset($sig, $offers, $roles, $needs, $posts);

        // ---- the web of follows, mutes, blocks, saves ---------------------------------------------------
        $this->step('follows', $c['entity_followers'] + $c['user_followers']);
        $ef = [];
        for ($i = 0; $i < 400; $i++) { // the heavy cohort follows hundreds of pages
            $ef[((($i % 5) + 2).':'.mt_rand(1, $c['entities']))] = true;
        }
        while (count($ef) < $c['entity_followers']) {
            $ef[mt_rand(1, $c['users']).':'.(int) (1 + $c['entities'] * $rand() ** 1.6)] = true;
        }
        $rows = [];
        $k = 0;
        foreach (array_keys($ef) as $pair) {
            [$u, $e] = explode(':', $pair);
            $rows[] = ['id' => ++$k, 'entity_id' => (int) $e, 'user_id' => (int) $u, 'created_at' => $stamp($now - mt_rand(0, 90) * 86400), 'updated_at' => $stamp($now)];
        }
        $this->bulk('pulse_entity_followers', $rows);

        $uf = [];
        while (count($uf) < $c['user_followers']) {
            $a = mt_rand(1, $c['users']);
            $b = mt_rand(1, $c['users']);
            $a !== $b && $uf["$a:$b"] = true;
        }
        $rows = [];
        $k = 0;
        foreach (array_keys($uf) as $pair) {
            [$a, $b] = explode(':', $pair);
            $rows[] = ['id' => ++$k, 'follower_id' => (int) $a, 'following_id' => (int) $b, 'created_at' => $stamp($now), 'updated_at' => $stamp($now)];
        }
        $this->bulk('pulse_followers', $rows);

        $rows = [];
        $mu = [];
        while (count($rows) < $c['mutes']) {
            $u = mt_rand(1, $c['users']);
            $kind = ['topic', 'topic', 'entity', 'user'][mt_rand(0, 3)];
            $target = $kind === 'topic' ? $topicOf() : (string) ($kind === 'entity' ? mt_rand(1, $c['entities']) : mt_rand(1, $c['users']));
            if (isset($mu["$u:$kind:$target"])) {
                continue;
            }
            $mu["$u:$kind:$target"] = true;
            $rows[] = ['id' => count($rows) + 1, 'user_id' => $u, 'kind' => $kind, 'target' => $target, 'created_at' => $stamp($now), 'updated_at' => $stamp($now)];
        }
        $this->bulk('pulse_mutes', $rows);

        $rows = [];
        $bl = [];
        while (count($rows) < $c['blocks']) {
            $a = mt_rand(1, $c['users']);
            $b = mt_rand(1, $c['users']);
            if ($a === $b || isset($bl["$a:$b"])) {
                continue;
            }
            $bl["$a:$b"] = true;
            $rows[] = ['id' => count($rows) + 1, 'blocker_id' => $a, 'blocked_id' => $b, 'created_at' => $stamp($now), 'updated_at' => $stamp($now)];
        }
        $this->bulk('pulse_blocks', $rows);

        $maxSignal = $sid;
        $rows = [];
        $sv = [];
        while (count($rows) < $c['saves']) {
            $u = mt_rand(1, $c['users']);
            $s = mt_rand(1, $maxSignal);
            if (isset($sv["$u:$s"])) {
                continue;
            }
            $sv["$u:$s"] = true;
            $rows[] = ['id' => count($rows) + 1, 'user_id' => $u, 'saveable_type' => 'signal', 'saveable_id' => $s, 'created_at' => $past(60), 'updated_at' => $stamp($now)];
        }
        $this->bulk('pulse_saves', $rows);

        // ---- conversations and messages -----------------------------------------------------------------
        $this->step('conversations', $c['conversations']);
        $this->seedLines($c, $liveEntities, $stamp, $now);

        // ---- prefs, notifications, audit, reports, budgets ----------------------------------------------
        $this->step('prefs, notifications, audit, reports', 0);
        $rows = [];
        for ($i = 1; $i <= min($c['prefs'], $c['users']); $i++) {
            $rows[] = ['id' => $i, 'user_id' => $i, 'channels' => json_encode(['in_app' => true, 'email' => true]), 'categories' => json_encode([]), 'frequency' => ['daily', 'daily', 'weekly', 'instant'][mt_rand(0, 3)],
                'digest' => 1, 'digest_hour' => '07:30', 'timezone' => 'Africa/Johannesburg', 'created_at' => $stamp($now), 'updated_at' => $stamp($now)];
        }
        $this->bulk('pulse_notification_prefs', $rows);

        $rows = [];
        for ($i = 1; $i <= $c['notifications']; $i++) {
            $rows[] = ['id' => sprintf('00000000-0000-4000-8000-%012d', $i), 'type' => 'App\\Notifications\\Bench', 'notifiable_type' => User::class, 'notifiable_id' => mt_rand(1, $c['users']),
                'data' => json_encode(['category' => 'lines', 'title' => 'Bench']), 'read_at' => $rand() < 0.6 ? $stamp($now) : null, 'created_at' => $past(30), 'updated_at' => $stamp($now)];
        }
        $this->bulk('notifications', $rows);

        $rows = [];
        for ($i = 1; $i <= $c['audit']; $i++) {
            $rows[] = ['id' => $i, 'actor_id' => mt_rand(1, $c['users']), 'action' => ['pulse.digest.sent', 'pulse.floor.save', 'pulse.floor.dismiss', 'pulse.line.knock'][mt_rand(0, 3)], 'subject_type' => 'signal', 'subject_id' => mt_rand(1, $maxSignal), 'created_at' => $past(90)];
        }
        $this->bulk('pulse_audit_log', $rows);

        $rows = [];
        for ($i = 1; $i <= $c['reports']; $i++) {
            $open = $rand() < 0.2;
            $rows[] = ['id' => $i, 'reporter_id' => $i, 'reportable_type' => 'offer', 'reportable_id' => mt_rand(1, $c['offers']), 'reason' => 'spam', 'status' => $open ? 'open' : 'resolved',
                'created_at' => $past(60), 'updated_at' => $stamp($now), 'resolved_at' => $open ? null : $past(30), 'resolution' => $open ? null : 'removed'];
        }
        $this->bulk('pulse_reports', $rows);

        $rows = [];
        for ($i = 1; $i <= min(5000, $c['users']); $i++) {
            $rows[] = ['id' => $i, 'user_id' => $i, 'window_started_at' => $past(7), 'spent' => mt_rand(0, 12), 'bonus' => 0, 'penalty' => 0, 'created_at' => $stamp($now), 'updated_at' => $stamp($now)];
        }
        $this->bulk('pulse_knock_budgets', $rows);

        $this->fixSequences();

        // ---- matches through the real matcher ----------------------------------------------------------
        $this->step('matches (real Matcher)', $c['matches']);
        $matcher = app(Matcher::class);
        $users = array_merge(range(2, 6), $this->sampleUsers($c['users'], min($c['users'] - 6, (int) ($c['matches'] / 60)), 7));
        $done = 0;
        foreach ($users as $uid) {
            $user = User::find($uid);
            $user && $matcher->forUser($user);
            if (++$done % 100 === 0) {
                $total = DB::table('pulse_matches')->count();
                $this->line("  {$done} users matched, {$total} rows");
                if ($total >= $c['matches']) {
                    break;
                }
            }
        }
        DB::table('pulse_matches')->where('id', '>', 0)->limit(1)->exists();
        $this->analyse();
    }

    /** @param list<int> $liveEntities */
    private function seedLines(array $c, array $liveEntities, callable $stamp, int $now): void
    {
        $rand = fn () => mt_rand() / mt_getrandmax();
        $kinds = ['enquiry', 'enquiry', 'enquiry', 'enquiry', 'enquiry', 'quote', 'quote', 'application', 'application', 'dm', 'invite', 'intro'];
        $messageId = 0;
        $convRows = [];
        $msgRows = [];
        $cuRows = [];
        $perConv = max(1, (int) round($c['messages'] / $c['conversations']));
        $bodies = ['Hi, is this still available?', 'Thanks for getting back to me. Can you do Friday?', 'Our quote is attached, valid for seven days.', 'That works for us.', 'Please confirm the delivery address.', 'Great, looking forward to it.'];
        $flush = function () use (&$convRows, &$msgRows, &$cuRows) {
            $this->bulk('pulse_conversations', $convRows);
            $this->bulk('pulse_messages', $msgRows);
            $this->bulk('pulse_conversation_user', $cuRows);
            $convRows = $msgRows = $cuRows = [];
        };

        for ($i = 1; $i <= $c['conversations']; $i++) {
            // 38% of the lines belong to the five heavy users and their pages, the rest are spread thin.
            $heavy = $i <= (int) ($c['conversations'] * 0.38);
            $h = ($i % 5) + 2;
            $created = $now - (int) ($rand() ** 1.3 * 90 * 86400);
            $toEntity = $rand() < 0.62;
            $entity = $toEntity ? ($heavy && $rand() < 0.7 ? (($h - 2) + 1) : $liveEntities[mt_rand(0, count($liveEntities) - 1)]) : null;
            $initiator = $heavy && ! $toEntity && $rand() < 0.3 ? $h : mt_rand(1, $c['users']);
            $recipient = $toEntity ? null : ($heavy && $initiator !== $h ? $h : mt_rand(1, $c['users']));
            if ($recipient === $initiator) {
                $recipient = $initiator % $c['users'] + 1;
            }
            $r = $rand();
            $status = $r < 0.15 ? 'requested' : ($r < 0.70 ? 'open' : ($r < 0.90 ? 'closed' : ($r < 0.98 ? 'declined' : 'spam')));
            $ignored = $status === 'requested' && $rand() < 0.2;
            $count = $heavy && $i <= 5 ? 250 : max(1, (int) round($perConv * (0.2 + $rand() * 1.8)));
            $lastAt = $created + (int) ($rand() * 20 * 86400);
            $lastAt = min($lastAt, $now);
            $side = $rand() < 0.5 ? 'initiator' : 'recipient';
            $answered = $status === 'open' || $status === 'closed';
            $outcome = $answered && $rand() < 0.08 ? ['won', 'hired', 'partnered', 'lost'][mt_rand(0, 3)] : 'none';

            $convRows[] = [
                'id' => $i, 'type' => 'direct', 'kind' => $kinds[mt_rand(0, count($kinds) - 1)], 'status' => $status, 'initiator_id' => $initiator, 'recipient_id' => $recipient,
                'entity_id' => $entity, 'context_type' => 'offer', 'context_id' => mt_rand(1, $c['offers']), 'context_title' => 'Bench offer '.mt_rand(1, 999),
                'card' => json_encode(['what' => 'Bench enquiry '.$i, 'where' => 'Johannesburg']), 'outcome' => $outcome, 'outcome_side' => $outcome === 'none' ? null : 'initiator',
                'outcome_initiator_at' => $outcome === 'none' ? null : $stamp($lastAt), 'outcome_recipient_at' => $outcome === 'none' ? null : $stamp($lastAt),
                'first_response_at' => $answered || $status === 'declined' ? $stamp($created + mt_rand(60, 90000)) : null, 'last_message_at' => $stamp($lastAt), 'last_message_side' => $side,
                'initiator_read_at' => $rand() < 0.6 ? $stamp($lastAt + 1) : null, 'recipient_read_at' => $rand() < 0.6 ? $stamp($lastAt + 1) : null,
                'ignored_at' => $ignored ? $stamp($created + 3600) : null, 'closed_at' => $status === 'closed' ? $stamp($lastAt) : null, 'closed_reason' => $status === 'closed' ? 'closed' : null,
                'created_at' => $stamp($created), 'updated_at' => $stamp($lastAt),
            ];

            $cuRows[] = ['pulse_conversation_id' => $i, 'user_id' => $initiator, 'side' => 'initiator', 'muted' => 0];
            if ($recipient !== null) {
                $cuRows[] = ['pulse_conversation_id' => $i, 'user_id' => $recipient, 'side' => 'recipient', 'muted' => 0];
            }

            for ($m = 0; $m < $count; $m++) {
                $from = $m % 2 === 0 ? 'initiator' : 'recipient';
                $msgRows[] = [
                    'id' => ++$messageId, 'pulse_conversation_id' => $i, 'user_id' => $from === 'initiator' ? $initiator : ($recipient ?? $initiator), 'body' => $bodies[$m % count($bodies)].' #'.$messageId,
                    'kind' => 'text', 'side' => $from, 'created_at' => $stamp(min($now, $created + $m * 600)), 'updated_at' => $stamp($lastAt),
                ];
            }

            if (count($msgRows) >= 4000 || count($convRows) >= 1000) {
                $flush();
            }
        }

        $flush();
        $this->line("  messages: {$messageId}");
    }

    private function wipe(): void
    {
        $tables = [
            'pulse_audit_log', 'pulse_reports', 'pulse_knock_budgets', 'pulse_quotes', 'pulse_messages', 'pulse_conversation_user', 'pulse_conversations', 'pulse_doors',
            'pulse_matches', 'pulse_saves', 'pulse_mutes', 'pulse_blocks', 'pulse_entity_blocks', 'pulse_followers', 'pulse_entity_followers', 'pulse_signals', 'pulse_needs', 'pulse_roles',
            'pulse_offers', 'pulse_entity_members', 'pulse_media_files', 'pulse_notification_prefs', 'pulse_reviews', 'pulse_posts', 'pulse_entities', 'pulse_profiles', 'notifications', 'users',
        ];

        $this->info('Wiping '.count($tables).' tables');

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE pulse_audit_log DISABLE TRIGGER USER');
            DB::unprepared('TRUNCATE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
            DB::unprepared('ALTER TABLE pulse_audit_log ENABLE TRIGGER USER');

            return;
        }

        DB::unprepared('PRAGMA foreign_keys = OFF');
        DB::unprepared('DROP TRIGGER IF EXISTS pulse_audit_log_no_delete');

        foreach ($tables as $t) {
            if (Schema::hasTable($t)) {
                DB::table($t)->delete();
            }
        }

        DB::unprepared('CREATE TRIGGER pulse_audit_log_no_delete BEFORE DELETE ON pulse_audit_log BEGIN SELECT RAISE(ABORT, \'pulse_audit_log is append only\'); END;');
        DB::unprepared('PRAGMA foreign_keys = ON');
    }

    private function fixSequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['users', 'pulse_profiles', 'pulse_entities', 'pulse_entity_members', 'pulse_offers', 'pulse_roles', 'pulse_needs', 'pulse_posts', 'pulse_signals', 'pulse_entity_followers',
            'pulse_followers', 'pulse_mutes', 'pulse_blocks', 'pulse_saves', 'pulse_conversations', 'pulse_messages', 'pulse_notification_prefs', 'pulse_audit_log', 'pulse_reports', 'pulse_knock_budgets'] as $t) {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$t}', 'id'), COALESCE((SELECT MAX(id) FROM {$t}), 1))");
        }
    }

    private function analyse(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('VACUUM ANALYZE');
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared('ANALYZE');
        }
    }

    /** @return list<int> */
    private function sampleUsers(int $max, int $n, int $from): array
    {
        mt_srand(9);
        $out = [];
        while (count($out) < $n) {
            $out[mt_rand($from, $max)] = true;
        }

        return array_keys($out);
    }

    private function gen(int $n, callable $row): \Generator
    {
        for ($i = 1; $i <= $n; $i++) {
            yield $row($i);
        }
    }

    /** @param iterable<array<string, mixed>> $rows */
    private function bulk(string $table, iterable $rows): void
    {
        $buffer = [];
        $limit = 500;

        foreach ($rows as $row) {
            $buffer[] = $row;
            $limit = max(20, min(1000, intdiv(60000, max(1, count($row)))));

            if (count($buffer) >= $limit) {
                DB::table($table)->insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            foreach (array_chunk($buffer, $limit) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
    }

    private function step(string $what, int $n): void
    {
        $this->line('Seeding '.$what.($n ? " ({$n})" : ''));
    }

    private function printCounts(): void
    {
        $out = [];
        foreach (['users', 'pulse_profiles', 'pulse_entities', 'pulse_offers', 'pulse_roles', 'pulse_needs', 'pulse_signals', 'pulse_matches', 'pulse_conversations', 'pulse_messages', 'pulse_saves', 'pulse_entity_followers', 'notifications'] as $t) {
            $out[] = [$t, number_format((int) DB::table($t)->count())];
        }
        $this->table(['table', 'rows'], $out);
    }

    // ================================================================================================ timing

    private function wants(string $group): bool
    {
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));

        return $only === [] || in_array($group, $only, true);
    }

    /**
     * @param  callable(int): mixed  $fn  receives the run number, so a scenario can rotate through users
     */
    private function time(string $name, callable $fn, ?int $runs = null, string $note = ''): void
    {
        $runs ??= (int) $this->option('runs');
        gc_collect_cycles();
        memory_reset_peak_usage();
        $times = [];
        $queries = [];

        for ($i = 0; $i < $runs; $i++) {
            $this->n = 0;
            $this->queryLog = [];
            $t = hrtime(true);
            try {
                $fn($i);
            } catch (\Throwable $e) {
                $this->results[$name] = ['error' => get_class($e).': '.mb_substr($e->getMessage(), 0, 160)];
                $this->warn("  {$name}: ".$this->results[$name]['error']);

                return;
            }
            $times[] = (hrtime(true) - $t) / 1e6;
            $queries[] = $this->n;
        }

        sort($times);
        $slowest = $this->queryLog;
        usort($slowest, fn ($a, $b) => $b[1] <=> $a[1]);

        $this->results[$name] = [
            'p50' => round($this->pct($times, 50), 1), 'p95' => round($this->pct($times, 95), 1), 'max' => round(end($times), 1), 'runs' => $runs,
            'queries' => (int) round(array_sum($queries) / max(1, count($queries))), 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1), 'note' => $note,
            'slowest' => array_map(fn ($q) => round($q[1], 1).' ms '.preg_replace('/\s+/', ' ', mb_substr($q[0], 0, 220)), array_slice($slowest, 0, 3)),
        ];
        $r = $this->results[$name];
        $this->line(sprintf('  %-28s p50 %8.1f  p95 %8.1f ms  %4d queries  %6.1f MB', $name, $r['p50'], $r['p95'], $r['queries'], $r['peak_mb']));
    }

    /** @param list<float> $sorted */
    private function pct(array $sorted, int $p): float
    {
        $i = (int) ceil($p / 100 * count($sorted)) - 1;

        return $sorted[max(0, min(count($sorted) - 1, $i))];
    }

    private function runScenarios(): void
    {
        $this->info('Timing');
        Cache::flush();
        $max = (int) DB::table('users')->max('id');
        $matched = DB::table('pulse_matches')->select('user_id')->groupBy('user_id')->havingRaw('COUNT(*) > 20')->orderBy('user_id')->limit(200)->pluck('user_id')->all();
        $typical = array_values(array_filter($matched, fn ($id) => $id > 6));
        $typical = array_slice($typical, 0, 40);
        $heavy = [2, 3, 4, 5, 6];
        $user = fn (int $id) => User::query()->find($id);
        $pickTypical = fn (int $i) => $user($typical[$i % max(1, count($typical))] ?? 7);
        $pickHeavy = fn (int $i) => $user($heavy[$i % 5]);

        if ($this->wants('floor')) {
            $floor = app(Floor::class);
            $later = rtrim(strtr(base64_encode('o:20'), '+/', '-_'), '=');
            $this->time('floor.first', fn ($i) => $floor->round($pickTypical($i), 'all', null));
            $this->time('floor.later', fn ($i) => $floor->round($pickTypical($i), 'all', $later));
            $this->time('floor.trade', fn ($i) => $floor->round($pickTypical($i), 'trade', null));
            $this->time('floor.heavy_user', fn ($i) => $floor->round($pickHeavy($i), 'all', null));
            $this->time('floor.saved_list', fn ($i) => $floor->savedList($pickTypical($i)));
        }

        if ($this->wants('search')) {
            $search = app(SearchService::class);
            $this->time('search.one_word', fn ($i) => $search->search($pickTypical($i), 'plumbing'));
            $this->time('search.three_words', fn ($i) => $search->search($pickTypical($i), 'bright accounting services'));
            $this->time('search.filtered', fn ($i) => $search->search($pickTypical($i), 'solar', ['kind' => 'offer', 'price_max' => 20000, 'mode' => 'local']));
            $this->time('search.no_hit', fn ($i) => $search->search($pickTypical($i), 'zzzqxv'));
            $this->time('search.suggest', fn ($i) => $search->suggest($pickTypical($i), 'plu'));
        }

        if ($this->wants('matcher')) {
            $matcher = app(Matcher::class);
            $this->time('matcher.forUser.typical', fn ($i) => $matcher->forUser($pickTypical($i)), 10);
            $this->time('matcher.forUser.heavy', fn ($i) => $matcher->forUser($pickHeavy($i)), 5);

            $wide = PulseSignal::query()->where('status', 'live')->where('kind', 'offer')->whereIn('reach', ['region', 'national'])->orderByDesc('id')->limit(10)->get();
            $this->time('matcher.forSignal.fanout', fn ($i) => $matcher->forSignal($wide[$i % max(1, $wide->count())]), 10);
        }

        if ($this->wants('match')) {
            $this->matchCycle();
        }

        if ($this->wants('index')) {
            $t = hrtime(true);
            $this->n = 0;
            memory_reset_peak_usage();
            Artisan::call('pulse:index', ['--full' => true]);
            $ms = (hrtime(true) - $t) / 1e6;
            $this->results['index.rebuild'] = ['p50' => round($ms), 'p95' => round($ms), 'max' => round($ms), 'runs' => 1, 'queries' => $this->n, 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1), 'note' => trim(Artisan::output())];
            $this->line(sprintf('  %-28s %8.1f s  %d queries  %.1f MB', 'index.rebuild', $ms / 1000, $this->n, $this->results['index.rebuild']['peak_mb']));
        }

        if ($this->wants('digest')) {
            $builder = app(DigestBuilder::class);
            $ids = range(7, min(1006, $max));
            $t = hrtime(true);
            $this->time('digest.build_1k', function () use ($builder, $ids, $user) {
                foreach ($ids as $id) {
                    $builder->build($user($id));
                }
            }, 1, 'up to 1000 users, one run');
            $this->results['digest.build_1k']['per_user_ms'] = round((hrtime(true) - $t) / 1e6 / max(1, count($ids)), 2);
            $this->time('digest.command_dry', fn () => Artisan::call('pulse:digest', ['--dry' => true]), 1);
        }

        if ($this->wants('inbox')) {
            $lines = app(LineService::class);
            foreach (['requests', 'open', 'sent', 'closed', 'ignored', 'all'] as $tab) {
                $this->time("inbox.{$tab}", fn ($i) => $lines->inbox($pickTypical($i), ['tab' => $tab]));
            }
            $this->time('inbox.unread', fn ($i) => $lines->inbox($pickTypical($i), ['tab' => 'open', 'unread' => true]));
            foreach (['requests', 'open', 'all'] as $tab) {
                $this->time("inbox.heavy.{$tab}", fn ($i) => $lines->inbox($pickHeavy($i), ['tab' => $tab]));
            }
            $this->time('inbox.heavy.page50', fn ($i) => $lines->inbox($pickHeavy($i), ['tab' => 'all', 'per_page' => 50, 'page' => 40]));
        }

        if ($this->wants('thread')) {
            $lines = app(LineService::class);
            $threads = [];
            foreach ($typical as $uid) {
                $c = PulseConversation::query()->where(fn ($w) => $w->where('initiator_id', $uid)->orWhere('recipient_id', $uid))->orderBy('id', 'desc')->first();
                $c && $threads[] = [$uid, $c->id];
                if (count($threads) >= 20) {
                    break;
                }
            }
            $this->time('thread.typical', function ($i) use ($lines, $threads, $user) {
                [$uid, $cid] = $threads[$i % count($threads)];
                $lines->thread($user($uid), PulseConversation::findOrFail($cid));
            });
            $big = PulseConversation::query()->where('id', 1)->first();
            $this->time('thread.heavy', fn () => $lines->thread($user($big->initiator_id), $big), null, '250 messages, 200 shown');
        }

        if ($this->wants('operator')) {
            $m = app(Metrics::class);
            [$from, $to] = $m->window(30);
            $this->time('operator.people', fn () => $m->people());
            $this->time('operator.listings', fn () => $m->liveListings());
            $this->time('operator.floor', fn () => $m->floor($from, $to));
            $this->time('operator.requests', fn () => $m->requests($from, $to));
            $this->time('operator.responses', fn () => $m->responses($from, $to));
            $this->time('operator.outcomes', fn () => $m->outcomes($from, $to));
            $this->time('operator.queue', fn () => $m->queue());
            $this->time('operator.exhausted', fn () => $m->knockBudgetExhausted());
            $this->time('operator.series', fn () => $m->knocksPerDay($from, $to));
            $this->time('operator.snapshot.cold', function () use ($m) {
                Cache::flush();
                $m->snapshot(30);
            }, 5);
            $this->time('operator.snapshot.warm', fn () => $m->snapshot(30), 10);
        }

        if ($this->wants('http')) {
            $this->http($typical, $heavy);
        }
    }

    /** One scheduled cycle as production runs it: a burst of changed signals and profiles, then pulse:match. */
    private function matchCycle(): void
    {
        $now = now();
        DB::table('pulse_signals')->where('status', 'live')->orderByDesc('id')->limit(400)->pluck('id')->chunk(200)->each(
            fn ($ids) => DB::table('pulse_signals')->whereIn('id', $ids)->update(['updated_at' => $now]),
        );
        DB::table('pulse_profiles')->where('id', '>', 10)->orderBy('id')->limit(400)->pluck('id')->chunk(200)->each(
            fn ($ids) => DB::table('pulse_profiles')->whereIn('id', $ids)->update(['updated_at' => $now]),
        );
        Cache::forget('pulse:match:last-run');

        $this->results['match.cycle'] = $this->command('pulse:match', ['--limit' => 400]);
        $this->results['match.cycle']['note'] = '400 changed signals + 400 changed profiles (one 10 minute tick)';
        $this->line(sprintf('  %-28s %8.1f s  %6.1f MB  %s', 'match.cycle', $this->results['match.cycle']['p50'] / 1000, $this->results['match.cycle']['peak_mb'], $this->results['match.cycle']['out']));

        if ($this->option('full-match')) {
            $this->results['match.full'] = $this->command('pulse:match', ['--all' => true, '--limit' => 400]);
            $this->line(sprintf('  %-28s %8.1f s  %6.1f MB  %s', 'match.full', $this->results['match.full']['p50'] / 1000, $this->results['match.full']['peak_mb'], $this->results['match.full']['out']));
        }
    }

    /** @return array<string, mixed> */
    private function command(string $name, array $args): array
    {
        $this->n = 0;
        memory_reset_peak_usage();
        $t = hrtime(true);
        Artisan::call($name, $args);
        $ms = (hrtime(true) - $t) / 1e6;

        return ['p50' => round($ms), 'p95' => round($ms), 'max' => round($ms), 'runs' => 1, 'queries' => $this->n, 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1), 'out' => trim(Artisan::output())];
    }

    /**
     * Whole pages through the HTTP kernel, signed in as a real user (session driver array, no network).
     *
     * @param  list<int>  $typical
     * @param  list<int>  $heavy
     */
    private function http(array $typical, array $heavy): void
    {
        $kernel = app(Kernel::class);
        $viewer = User::find($typical[0] ?? 7);
        $convo = PulseConversation::query()->where('initiator_id', $viewer->id)->orderByDesc('id')->first();
        $entity = DB::table('pulse_entities')->where('status', 'live')->where('id', '>', 100)->orderBy('id')->first();
        $offer = DB::table('pulse_offers as o')->join('pulse_entities as e', 'e.id', '=', 'o.entity_id')
            ->where('o.status', 'live')->where('o.moderation', 'approved')->where('e.status', 'live')->where('e.moderation', 'approved')
            ->whereNull('o.deleted_at')->orderBy('o.id')->first(['o.*']);
        $offerEntity = DB::table('pulse_entities')->find($offer->entity_id);
        $signal = DB::table('pulse_signals')->where('kind', 'offer')->where('status', 'live')->orderByDesc('id')->first();

        $hit = function (string $name, string $uri, ?User $as = null, int $runs = 8) use ($kernel, $viewer) {
            $as ??= $viewer;
            $status = null;
            $bytes = 0;

            $this->time($name, function () use ($kernel, $uri, $as, &$status, &$bytes) {
                Auth::forgetGuards(); // the sanctum guard keeps the first user it resolved
                Auth::guard('web')->setUser($as);
                $request = Request::create($uri, 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html, application/json', 'REMOTE_ADDR' => '127.0.0.1']);
                $response = $kernel->handle($request);
                $status = $response->getStatusCode();
                $bytes = strlen((string) $response->getContent());
            }, $runs);

            if (isset($this->results[$name]['p50'])) {
                $this->results[$name]['status'] = $status;
                $this->results[$name]['bytes'] = $bytes;
            }
        };

        $hit('http.pulse_check', '/api/pulse/pulse-check', null, 40);
        $hit('http.pulse_check_heavy', '/api/pulse/pulse-check', User::find($heavy[0]), 40);
        $hit('http.floor', '/floor');
        $hit('http.search', '/search?q=plumbing');
        $hit('http.entity_page', '/b/'.$entity->slug);
        $hit('http.offer_page', '/b/'.$offerEntity->slug.'/o/'.$offer->slug);
        $hit('http.signal_show', '/s/'.$signal->id);
        $hit('http.lines', '/lines');
        $convo && $hit('http.line_thread', '/lines/'.$convo->id);
        $hit('http.operator', '/operator', User::find(1), 5);
    }

    // ================================================================================================ report

    private function report(): void
    {
        $rows = [];

        foreach ($this->results as $name => $r) {
            if (isset($r['error'])) {
                $rows[] = [$name, 'ERROR', '', '', '', '', mb_substr($r['error'], 0, 60)];

                continue;
            }

            $target = self::TARGETS[$name] ?? null;
            $ok = $target === null ? '' : ($r['p95'] <= $target ? 'ok' : 'MISS');
            $rows[] = [$name, $r['p50'], $r['p95'], $r['queries'], $r['peak_mb'], $target ?? '', $ok];
        }

        $this->newLine();
        $this->table(['scenario', 'p50 ms', 'p95 ms', 'queries', 'peak MB', 'target', ''], $rows);

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode([
                'driver' => DB::connection()->getDriverName(), 'database' => DB::connection()->getDatabaseName(), 'scale' => $this->scale, 'counts' => $this->count,
                'php' => PHP_VERSION, 'at' => now()->toIso8601String(), 'results' => $this->results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Results written to {$path}");
        }
    }
}
