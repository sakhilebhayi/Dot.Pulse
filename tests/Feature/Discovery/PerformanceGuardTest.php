<?php

namespace Tests\Feature\Discovery;

use App\Console\Commands\PulseMatch;
use App\Models\PulseConversation;
use App\Models\User;
use App\Services\Pulse\Discovery\Floor;
use App\Services\Pulse\Discovery\Matcher;
use App\Services\Pulse\Discovery\SearchService;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Operator\Metrics;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\DiscoveryFixtures;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

/**
 * R-41 performance guards (docs/programme/R41_PERFORMANCE.md). The rule each one pins: the number of queries a hot
 * path runs does not depend on how much data there is. A page that costs 14 queries for 5 rows and 14 for 500 is
 * fine; one that costs 14 and then 500 is an N+1 and fails here. The timings themselves live in `pulse:bench`.
 */
class PerformanceGuardTest extends TestCase
{
    use DiscoveryFixtures;
    use LinesFixtures;
    use RefreshDatabase;

    /**
     * Queries of the second run: the first one pays for one-off schema look-ups that are cached for the process.
     *
     * @param  callable(): mixed  $fn
     */
    private function queries(callable $fn, bool $warm = true): int
    {
        $warm && $fn();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $n = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        return $n;
    }

    // ---- the Floor, search -------------------------------------------------------------------------------------

    private function addOfferMatches(User $me, int $n, string $topic = 'bookkeeping'): void
    {
        foreach (range(1, $n) as $i) {
            $owner = $this->dUser();
            $entity = $this->dEntity($owner);
            $this->dMatch($me, $this->dOfferSignal($entity, [$topic]), 60 - $i % 20);
        }
    }

    public function test_a_floor_round_costs_the_same_queries_for_a_small_pool_and_a_large_one(): void
    {
        $me = $this->dUser();
        $this->addOfferMatches($me, 6);
        $small = $this->queries(fn () => app(Floor::class)->round($me));

        $this->addOfferMatches($me, 30);
        $large = $this->queries(fn () => app(Floor::class)->round($me));

        $this->assertSame($small, $large, 'the Floor must not query per beat');
        $this->assertLessThanOrEqual(20, $large);
    }

    public function test_a_later_floor_batch_costs_no_more_than_the_first(): void
    {
        $me = $this->dUser();
        $this->addOfferMatches($me, 45);
        $later = rtrim(strtr(base64_encode('o:20'), '+/', '-_'), '=');

        $first = $this->queries(fn () => app(Floor::class)->round($me));
        $second = $this->queries(fn () => app(Floor::class)->round($me, 'all', $later));

        $this->assertLessThanOrEqual($first, $second);
    }

    public function test_search_costs_the_same_queries_for_a_few_results_and_many(): void
    {
        $me = $this->dUser();
        $this->addOfferMatches($me, 4);
        $few = $this->queries(fn () => app(SearchService::class)->search($me, 'bookkeeping'));

        $this->addOfferMatches($me, 30);
        $many = $this->queries(fn () => app(SearchService::class)->search($me, 'bookkeeping'));

        $this->assertSame($few, $many, 'search must not query per result');
        $this->assertLessThanOrEqual(25, $many);
    }

    // ---- lines -------------------------------------------------------------------------------------------------

    public function test_the_inbox_costs_the_same_queries_for_three_lines_and_twenty(): void
    {
        $page = $this->page(null, ['contact_policy' => 'anyone']);
        $owner = $this->responderOf($page);

        foreach (range(1, 3) as $i) {
            $this->openLine($this->person(), $page);
        }
        $few = $this->queries(fn () => app(LineService::class)->inbox($owner, ['tab' => 'open']));

        foreach (range(1, 17) as $i) {
            $this->openLine($this->person(), $page);
        }
        $many = $this->queries(fn () => app(LineService::class)->inbox($owner, ['tab' => 'open', 'per_page' => 20]));

        $this->assertSame($few, $many, 'the inbox must not query per line');
        $this->assertLessThanOrEqual(10, $many);
    }

    public function test_the_visible_lines_scope_reads_pages_as_a_list_not_a_sub_select(): void
    {
        $page = $this->page();
        $owner = $this->responderOf($page);
        $this->openLine($this->person(), $page);

        DB::flushQueryLog();
        DB::enableQueryLog();
        PulseConversation::query()->visibleTo($owner)->count();
        $sql = collect(DB::getQueryLog())->last()['query'];
        DB::disableQueryLog();

        $this->assertStringNotContainsString('select "entity_id" from "pulse_entity_members"', $sql, 'a sub-select in the OR forces a scan of every line');
    }

    public function test_pulse_check_is_two_queries_whatever_the_inbox_holds(): void
    {
        $page = $this->page(null, ['contact_policy' => 'anyone']);
        $owner = $this->responderOf($page);

        foreach (range(1, 12) as $i) {
            $this->openLine($this->person(), $page);
        }

        $this->actingAs($owner);
        $n = $this->queries(fn () => $this->getJson('/api/pulse/pulse-check')->assertOk());

        $this->assertLessThanOrEqual(2, $n);
    }

    // ---- the operator ------------------------------------------------------------------------------------------

    public function test_the_operator_snapshot_costs_the_same_queries_for_few_lines_and_many(): void
    {
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $metrics = app(Metrics::class);

        foreach (range(1, 3) as $i) {
            app(LineService::class)->knock($this->person(), ['entity' => $page->id], 'enquiry', $this->card());
        }
        $few = $this->queries(fn () => $metrics->snapshot(30));

        foreach (range(1, 15) as $i) {
            app(LineService::class)->knock($this->person(), ['entity' => $page->id], 'enquiry', $this->card());
        }
        $many = $this->queries(fn () => $metrics->snapshot(30));

        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(60, $many);
    }

    public function test_the_dashboard_snapshot_is_reused_for_a_minute_per_range_and_can_be_flushed(): void
    {
        $metrics = app(Metrics::class);
        $metrics->cachedSnapshot(30);

        $this->assertSame(0, $this->queries(fn () => $metrics->cachedSnapshot(30), false), 'the second look costs no queries');
        $this->assertGreaterThan(0, $this->queries(fn () => $metrics->cachedSnapshot(7), false), 'another range is its own figure');

        Metrics::flush();
        $this->assertGreaterThan(0, $this->queries(fn () => $metrics->cachedSnapshot(30), false), 'a flush forgets the figures');
    }

    // ---- matching ----------------------------------------------------------------------------------------------

    public function test_matching_a_signal_costs_per_group_of_people_not_per_person(): void
    {
        $geo = $this->dGeo();
        $owner = $this->dUser(['place' => $geo->jhb]);
        $entity = $this->dEntity($owner, ['place_id' => $geo->jhb->id]);
        $signal = $this->dOfferSignal($entity, ['bookkeeping'], ['place' => $geo->jhb, 'reach' => 'region']);

        foreach (range(1, 8) as $i) {
            $this->dUser(['place' => $geo->jhb, 'interests' => ['bookkeeping']]);
        }
        $few = $this->queries(fn () => app(Matcher::class)->forSignal($signal));

        foreach (range(1, 40) as $i) {
            $this->dUser(['place' => $geo->jhb, 'interests' => ['bookkeeping']]);
        }
        $many = $this->queries(fn () => app(Matcher::class)->forSignal($signal));

        $this->assertSame($few, $many, 'five times the people, the same queries');
        $this->assertGreaterThan(40, DB::table('pulse_matches')->where('signal_id', $signal->id)->count(), 'and they all got their row');
    }

    public function test_for_signals_writes_the_same_rows_as_one_signal_at_a_time_and_drops_people_who_no_longer_match(): void
    {
        $geo = $this->dGeo();
        $owner = $this->dUser(['place' => $geo->jhb]);
        $entity = $this->dEntity($owner, ['place_id' => $geo->jhb->id]);
        $a = $this->dOfferSignal($entity, ['bookkeeping'], ['place' => $geo->jhb, 'reach' => 'region']);
        $b = $this->dOfferSignal($entity, ['plumbing'], ['place' => $geo->jhb, 'reach' => 'region']);
        $people = collect(range(1, 6))->map(fn () => $this->dUser(['place' => $geo->jhb, 'interests' => ['bookkeeping']]));

        app(Matcher::class)->forSignals(collect([$a, $b]));
        $together = DB::table('pulse_matches')->orderBy('user_id')->orderBy('signal_id')->get(['user_id', 'signal_id', 'score'])->map(fn ($r) => (array) $r)->all();

        DB::table('pulse_matches')->delete();
        app(Matcher::class)->forSignal($a);
        app(Matcher::class)->forSignal($b);
        $apart = DB::table('pulse_matches')->orderBy('user_id')->orderBy('signal_id')->get(['user_id', 'signal_id', 'score'])->map(fn ($r) => (array) $r)->all();

        $this->assertNotEmpty($together);
        $this->assertSame($apart, $together);

        // One person stops matching (muted topic): the next run removes their row and leaves the others.
        $gone = $people[0];
        $this->dMute($gone, 'entity', (string) $entity->id);
        app(Matcher::class)->forSignals(collect([$a, $b]));

        $this->assertSame(0, DB::table('pulse_matches')->where('user_id', $gone->id)->count());
        $this->assertGreaterThan(0, DB::table('pulse_matches')->where('user_id', $people[1]->id)->count());
    }

    // ---- the commands ------------------------------------------------------------------------------------------

    public function test_every_pulse_command_is_guarded_against_overlap_with_a_lock_that_expires(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach (['pulse:index', 'pulse:match', 'pulse:digest', 'pulse:expire', 'pulse:lines:sweep'] as $command) {
            $event = $events->first(fn ($e) => str_contains((string) $e->command, $command));

            $this->assertNotNull($event, $command);
            $this->assertTrue($event->withoutOverlapping, $command);
            $this->assertLessThanOrEqual(60, $event->expiresAt, "{$command}: a crashed run must not hold the shared crontab for a day");
        }
    }

    public function test_pulse_match_does_nothing_while_another_run_holds_the_lock(): void
    {
        $this->dSignal(['updated_at' => now()]);

        $lock = Cache::lock(PulseMatch::LOCK, 60);
        $this->assertTrue($lock->get());

        $this->assertSame(0, Artisan::call('pulse:match'));
        $this->assertStringContainsString('still going', Artisan::output());
        $this->assertSame(0, DB::table('pulse_matches')->count());

        $lock->release();
        Artisan::call('pulse:match');
        $this->assertStringContainsString('Signals matched', Artisan::output());
    }

    public function test_pulse_match_saves_its_place_when_it_runs_out_of_room_and_carries_on_next_time(): void
    {
        $geo = $this->dGeo();
        $owner = $this->dUser(['place' => $geo->jhb]);
        $entity = $this->dEntity($owner, ['place_id' => $geo->jhb->id]);
        $signals = collect(range(1, 3))->map(fn ($i) => $this->dOfferSignal($entity, ['bookkeeping'], ['place' => $geo->jhb, 'reach' => 'region']));
        $this->dUser(['place' => $geo->jhb, 'interests' => ['bookkeeping']]);
        DB::table('pulse_signals')->update(['updated_at' => now()]);
        DB::table('pulse_matches')->delete();

        Artisan::call('pulse:match', ['--limit' => 1]);
        $this->assertStringContainsString('more to do', Artisan::output());
        $this->assertNotNull(Cache::get(PulseMatch::RESUME), 'the place is saved');
        $this->assertNull(Cache::get(PulseMatch::MARK), 'the window is not marked done while work is left');

        foreach (range(1, 6) as $i) {
            Artisan::call('pulse:match', ['--limit' => 1]);
            if (! str_contains(Artisan::output(), 'more to do')) {
                break;
            }
        }

        $this->assertNull(Cache::get(PulseMatch::RESUME), 'finished: nothing left to resume');
        $this->assertNotNull(Cache::get(PulseMatch::MARK));
        // The page's own business signal is indexed too now, so count the live signals rather than the offers made here.
        $this->assertGreaterThanOrEqual($signals->count(), DB::table('pulse_signals')->where('status', 'live')->count());
        $this->assertSame(DB::table('pulse_signals')->where('status', 'live')->count(), DB::table('pulse_matches')->distinct()->count('signal_id'), 'every signal was matched, none dropped by the limit');
    }

    // ---- the migration -----------------------------------------------------------------------------------------

    public function test_the_performance_indexes_migration_goes_up_and_down_and_is_idempotent(): void
    {
        $migration = require database_path('migrations/2026_11_08_900000_pulse_perf_indexes.php');
        $want = [
            ['pulse_entity_members', 'pulse_entity_members_user_role_index'],
            ['pulse_followers', 'pulse_followers_following_index'],
            ['pulse_conversations', 'pulse_conversations_entity_created_index'],
            ['pulse_messages', 'pulse_messages_conversation_id_index'],
            ['pulse_profiles', 'pulse_profiles_place_user_index'],
        ];

        foreach ($want as [$table, $index]) {
            $this->assertTrue(Schema::hasIndex($table, $index), "{$index} exists after migrate");
        }
        $this->assertTrue(Schema::hasIndex('pulse_profiles', ['user_id'], 'unique'), 'a profile is unique per user');
        $this->assertTrue(Schema::hasIndex('pulse_notification_prefs', ['user_id'], 'unique'));

        $migration->up(); // already applied: must not fail
        $migration->down();

        foreach ($want as [$table, $index]) {
            $this->assertFalse(Schema::hasIndex($table, $index), "{$index} is gone after down");
        }
        $this->assertFalse(Schema::hasIndex('pulse_profiles', ['user_id'], 'unique'));

        $migration->down(); // twice is fine
        $migration->up();

        foreach ($want as [$table, $index]) {
            $this->assertTrue(Schema::hasIndex($table, $index));
        }
        $this->assertTrue(Schema::hasIndex('pulse_profiles', ['user_id'], 'unique'));
    }

    public function test_the_performance_migration_keeps_going_when_a_profile_is_already_duplicated(): void
    {
        $migration = require database_path('migrations/2026_11_08_900000_pulse_perf_indexes.php');
        $migration->down();

        $u = User::factory()->create();
        foreach ([1, 2] as $_) {
            DB::table('pulse_profiles')->insert(['user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        $migration->up();

        $this->assertFalse(Schema::hasIndex('pulse_profiles', ['user_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex('pulse_profiles', ['user_id']), 'a plain index keeps the lookup fast');
    }
}
