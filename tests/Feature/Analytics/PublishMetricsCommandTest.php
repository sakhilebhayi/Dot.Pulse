<?php

namespace Tests\Feature\Analytics;

use App\Jobs\SendAnalyticsSnapshot;
use App\Models\PulseComment;
use App\Models\PulsePost;
use App\Models\PulseReaction;
use App\Models\User;
use App\Services\Analytics\AnalyticsClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

class PublishMetricsCommandTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    private const URL = 'https://analytics.test/api/ingest/snapshots';

    private const SECRET = 'analytics-ingest-secret-for-tests-0001';

    private const COMPANY = '5b0f2d3e-8a41-4c7e-9d62-3f1a7c9e0b44';

    /** The exact keys Dot.Analytics' config/metrics.php defines for the pulse platform. */
    private const CONTRACT_KEYS = ['posts_7d', 'active_users_7d', 'held_posts'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:30:00');
        Cache::flush();
        config(['analytics' => [
            'url' => self::URL, 'secret' => self::SECRET, 'platform' => 'pulse', 'company_uuid' => self::COMPANY,
            'timeout' => 5, 'breaker' => ['threshold' => 3, 'cooldown' => 300],
        ]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedActivity(): void
    {
        $post = PulsePost::factory()->create(['status' => 'published', 'published_at' => now()->subDay()]);
        PulsePost::factory()->create(['status' => 'published', 'published_at' => now()->subHours(2)]);
        PulsePost::factory()->create(['status' => 'flagged']);
        PulseComment::factory()->create(['pulse_post_id' => $post->id]);
        PulseReaction::create(['user_id' => User::factory()->create()->id, 'reactable_type' => PulsePost::class, 'reactable_id' => $post->id, 'emoji' => '👍']);
    }

    public function test_it_posts_one_signed_snapshot_with_exactly_the_contract_shape(): void
    {
        Http::fake([self::URL => Http::response(['replayed' => false], 201)]);
        $this->seedActivity();

        $this->artisan('pulse:publish-metrics')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $body = $request->body();
            $json = json_decode($body, true);
            $timestamp = $request->header('X-InfoDot-Timestamp')[0] ?? '';

            return $request->url() === self::URL
                && ($request->header('X-InfoDot-Signature')[0] ?? '') === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET)
                && array_keys($json) === ['platform', 'company_uuid', 'taken_at', 'period', 'metrics']
                && $json['platform'] === 'pulse'
                && $json['company_uuid'] === self::COMPANY
                && $json['taken_at'] === '2026-11-05T00:00:00Z'
                && $json['period'] === 'day'
                && array_keys($json['metrics']) === self::CONTRACT_KEYS
                && $json['metrics'] === ['posts_7d' => 2, 'active_users_7d' => 4, 'held_posts' => 1];
        });
    }

    public function test_the_platform_key_comes_from_config(): void
    {
        config(['analytics.platform' => 'pulse-staging']);
        Http::fake();

        Artisan::call('pulse:publish-metrics', ['--dry-run' => true]);

        $this->assertStringContainsString('"platform": "pulse-staging"', Artisan::output());
    }

    public function test_taken_at_is_the_start_of_the_utc_day_so_a_replay_is_idempotent_upstream(): void
    {
        Http::fake();
        foreach (['2026-11-05 00:00:01', '2026-11-05 09:30:00', '2026-11-05 23:59:59'] as $moment) {
            Carbon::setTestNow($moment);
            $this->artisan('pulse:publish-metrics')->assertSuccessful();
        }
        Carbon::setTestNow('2026-11-06 00:00:00');
        $this->artisan('pulse:publish-metrics')->assertSuccessful();

        $days = collect(Http::recorded())->map(fn ($pair) => json_decode($pair[0]->body(), true)['taken_at'])->all();
        $this->assertSame(['2026-11-05T00:00:00Z', '2026-11-05T00:00:00Z', '2026-11-05T00:00:00Z', '2026-11-06T00:00:00Z'], $days);
    }

    public function test_a_job_that_runs_after_midnight_still_carries_the_day_it_was_queued_for(): void
    {
        Queue::fake();
        Http::fake();
        Carbon::setTestNow('2026-11-05 23:58:00');
        $this->artisan('pulse:publish-metrics')->assertSuccessful();

        $jobs = Queue::pushed(SendAnalyticsSnapshot::class);
        $this->assertCount(1, $jobs);
        Carbon::setTestNow('2026-11-06 03:00:00');
        app(AnalyticsClient::class)->deliver($jobs->first()->payload);

        Http::assertSent(fn (Request $r) => json_decode($r->body(), true)['taken_at'] === '2026-11-05T00:00:00Z'
            && $r->header('X-InfoDot-Timestamp')[0] === (string) Carbon::parse('2026-11-06 03:00:00')->timestamp);
    }

    public function test_the_snapshot_is_queued_not_sent_inside_the_command(): void
    {
        Queue::fake();
        Http::fake();

        $this->artisan('pulse:publish-metrics')->assertSuccessful();

        Queue::assertPushed(SendAnalyticsSnapshot::class, 1);
        Http::assertNothingSent();
    }

    public function test_blank_url_secret_or_company_uuid_sends_and_queues_nothing(): void
    {
        Http::fake();
        Queue::fake();
        $configured = config('analytics');

        foreach ([['url' => ''], ['url' => null], ['secret' => ''], ['secret' => null], ['company_uuid' => ''], ['company_uuid' => null], ['url' => '', 'secret' => '', 'company_uuid' => '']] as $blank) {
            config(['analytics' => array_merge($configured, $blank)]);

            $this->artisan('pulse:publish-metrics')->assertSuccessful();
        }

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_the_disabled_run_says_why_in_words_without_printing_any_secret(): void
    {
        config(['analytics.secret' => '']);

        $this->artisan('pulse:publish-metrics')
            ->expectsOutputToContain('not configured')
            ->doesntExpectOutputToContain(self::SECRET)
            ->assertSuccessful();
    }

    public function test_dry_run_prints_the_payload_and_sends_and_queues_nothing(): void
    {
        Http::fake();
        Queue::fake();
        $this->seedActivity();

        $exit = Artisan::call('pulse:publish-metrics', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertStringContainsString('"taken_at": "2026-11-05T00:00:00Z"', $output);
        $this->assertStringContainsString('"posts_7d": 2', $output);
        $this->assertStringContainsString('"held_posts": 1', $output);
        $this->assertStringContainsString(self::COMPANY, $output);
        $this->assertStringNotContainsString(self::SECRET, $output);
    }

    public function test_dry_run_still_works_when_nothing_is_configured_and_says_nothing_would_be_sent(): void
    {
        config(['analytics' => array_merge(config('analytics'), ['url' => '', 'secret' => '', 'company_uuid' => ''])]);
        Http::fake();

        $exit = Artisan::call('pulse:publish-metrics', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"metrics"', $output);
        $this->assertStringContainsString('would not be sent', $output);
        Http::assertNothingSent();
    }

    public function test_an_analytics_outage_never_fails_the_command_and_the_breaker_opens(): void
    {
        $this->seedActivity();
        Http::fake(['*' => Http::response('down', 500)]);

        for ($i = 0; $i < 5; $i++) {
            $this->artisan('pulse:publish-metrics')->assertSuccessful();
        }

        // sync queue: each run is one job; the job retries are released on a real queue, so the breaker
        // opens after three failed deliveries and the rest never reach the network
        Http::assertSentCount(3);
        $this->assertSame(2, PulsePost::where('status', 'published')->count(), 'the app data is untouched');
    }

    public function test_a_429_or_a_timeout_does_not_fail_the_command(): void
    {
        Http::fake(['*' => Http::response('slow down', 429)]);
        $this->artisan('pulse:publish-metrics')->assertSuccessful();

        Cache::flush();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $this->artisan('pulse:publish-metrics')->assertSuccessful();

        $this->addToAssertionCount(1);
    }

    public function test_a_queue_that_cannot_accept_the_job_does_not_fail_the_command(): void
    {
        $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue backend down')));

        $this->artisan('pulse:publish-metrics')->assertSuccessful();

        $this->addToAssertionCount(1);
    }

    public function test_no_row_level_data_ever_reaches_the_raw_body(): void
    {
        Http::fake([self::URL => Http::response([], 201)]);
        $sentinels = [
            'S-NAME-'.str_repeat('n', 60), 'S-EMAIL-'.str_repeat('e', 40).'@sentinel.example',
            'S-TITLE-'.str_repeat('t', 60), 'S-BODY-'.str_repeat('b', 60), 'S-COMMUNITY-'.str_repeat('c', 60),
            'S-COMMENT-'.str_repeat('m', 60),
        ];
        $author = User::factory()->create(['name' => $sentinels[0], 'email' => $sentinels[1]]);
        $community = $this->publicCommunity(['name' => $sentinels[4], 'slug' => 'sentinel-community-slug-'.str_repeat('x', 30)]);
        $post = PulsePost::factory()->create([
            'user_id' => $author->id, 'community_id' => $community->id, 'title' => $sentinels[2], 'body' => $sentinels[3],
            'status' => 'published', 'published_at' => now()->subHour(),
        ]);
        PulseComment::factory()->create(['pulse_post_id' => $post->id, 'user_id' => $author->id, 'body' => $sentinels[5]]);
        PulsePost::factory()->create(['status' => 'flagged', 'title' => $sentinels[2], 'body' => $sentinels[3]]);

        $this->artisan('pulse:publish-metrics')->assertSuccessful();

        Http::assertSent(function (Request $request) use ($sentinels, $author) {
            $body = $request->body();
            foreach ($sentinels as $sentinel) {
                if (str_contains($body, $sentinel) || str_contains($body, substr($sentinel, 0, 12))) {
                    return false;
                }
            }

            return ! str_contains($body, (string) $author->email)
                && ! str_contains($body, 'sentinel-community-slug-')
                && strlen($body) < 300
                && json_decode($body, true)['metrics'] === ['posts_7d' => 1, 'active_users_7d' => 1, 'held_posts' => 1];
        });
    }

    public function test_it_is_scheduled_daily_in_utc(): void
    {
        Artisan::call('list');
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'pulse:publish-metrics'));

        $this->assertNotNull($event, 'pulse:publish-metrics is not scheduled');
        $this->assertSame('10 0 * * *', $event->expression);
        $this->assertSame('UTC', (string) $event->timezone);
    }
}
