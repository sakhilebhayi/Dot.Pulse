<?php

namespace Tests\Feature\Analytics;

use App\Jobs\SendAnalyticsSnapshot;
use App\Services\Analytics\AnalyticsClient;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnalyticsClientTest extends TestCase
{
    private const URL = 'https://analytics.test/api/ingest/snapshots';

    private const SECRET = 'analytics-ingest-secret-for-tests-0001';

    private const COMPANY = '5b0f2d3e-8a41-4c7e-9d62-3f1a7c9e0b44';

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:30:00');
        Cache::flush();
        config(['analytics' => [
            'url' => self::URL, 'secret' => self::SECRET, 'platform' => 'pulse', 'company_uuid' => self::COMPANY,
            'timeout' => 5, 'breaker' => ['threshold' => 3, 'cooldown' => 300],
        ]]);
        $this->payload = [
            'platform' => 'pulse', 'company_uuid' => self::COMPANY, 'taken_at' => '2026-11-05T00:00:00Z', 'period' => 'day',
            'metrics' => ['posts_7d' => 4, 'active_users_7d' => 3, 'held_posts' => 1],
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function client(): AnalyticsClient
    {
        return app(AnalyticsClient::class);
    }

    public function test_it_posts_the_payload_signed_with_hmac_over_timestamp_dot_raw_body(): void
    {
        Http::fake([self::URL => Http::response(['replayed' => false], 201)]);

        $this->assertSame(AnalyticsClient::DONE, $this->client()->deliver($this->payload));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $body = $request->body();
            $timestamp = $request->header('X-InfoDot-Timestamp')[0] ?? '';
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);

            return $request->url() === self::URL
                && $request->method() === 'POST'
                && ($request->header('X-InfoDot-Signature')[0] ?? '') === $expected
                && $timestamp === (string) now()->timestamp
                && json_decode($body, true) === $this->payload
                && str_contains($request->header('Content-Type')[0] ?? '', 'application/json')
                && ! str_contains($body, self::SECRET);
        });
    }

    public function test_the_request_uses_a_five_second_timeout(): void
    {
        $seen = null;
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response([], 201);
        });

        $this->client()->deliver($this->payload);

        $this->assertSame(5, (int) ($seen['timeout'] ?? 0));
        $this->assertSame(5, (int) ($seen['connect_timeout'] ?? 0));
    }

    public function test_a_retry_is_signed_afresh_with_a_new_timestamp(): void
    {
        Http::fake([self::URL => Http::response([], 201)]);

        $this->client()->deliver($this->payload);
        Carbon::setTestNow(now()->addMinutes(10));
        $this->client()->deliver($this->payload);

        $records = Http::recorded();
        $stamps = collect($records)->map(fn ($pair) => $pair[0]->header('X-InfoDot-Timestamp')[0])->all();
        $this->assertCount(2, array_unique($stamps), 'a delayed retry must not carry a stale timestamp');
        // the same day is identified by the same taken_at, so the second send is a safe replay upstream
        $takenAt = collect($records)->map(fn ($pair) => json_decode($pair[0]->body(), true)['taken_at'])->unique()->all();
        $this->assertSame(['2026-11-05T00:00:00Z'], $takenAt);
    }

    public function test_it_is_disabled_when_the_url_secret_or_company_is_blank(): void
    {
        Http::fake();
        $configured = config('analytics');

        foreach ([['url' => ''], ['url' => null], ['secret' => ''], ['secret' => null], ['company_uuid' => ''], ['company_uuid' => null]] as $blank) {
            config(['analytics' => array_merge($configured, $blank)]);
            $this->assertFalse($this->client()->enabled(), json_encode($blank));
            $this->assertSame(AnalyticsClient::GIVE_UP, $this->client()->deliver($this->payload));
        }

        Http::assertNothingSent();
    }

    public function test_it_is_enabled_only_with_all_three_set(): void
    {
        $this->assertTrue($this->client()->enabled());
    }

    public function test_transient_failures_ask_the_queue_to_retry_and_permanent_ones_do_not(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('x', 500)->push('x', 503)->push('x', 429)
            ->push('x', 401)->push('x', 404)->push('x', 422)
            ->push([], 200)->push([], 201)
            ->pushFailedConnection()]);
        config(['analytics.breaker.threshold' => 1000]);

        $this->assertSame(AnalyticsClient::RETRY, $this->client()->deliver($this->payload), '500');
        $this->assertSame(AnalyticsClient::RETRY, $this->client()->deliver($this->payload), '503');
        $this->assertSame(AnalyticsClient::RETRY, $this->client()->deliver($this->payload), '429');
        $this->assertSame(AnalyticsClient::GIVE_UP, $this->client()->deliver($this->payload), '401: our secret is wrong');
        $this->assertSame(AnalyticsClient::GIVE_UP, $this->client()->deliver($this->payload), '404: company unknown');
        $this->assertSame(AnalyticsClient::GIVE_UP, $this->client()->deliver($this->payload), '422: payload refused');
        $this->assertSame(AnalyticsClient::DONE, $this->client()->deliver($this->payload), '200: replay of a stored day');
        $this->assertSame(AnalyticsClient::DONE, $this->client()->deliver($this->payload), '201');
        $this->assertSame(AnalyticsClient::RETRY, $this->client()->deliver($this->payload), 'connection failure');
    }

    public function test_a_timeout_or_unexpected_error_never_throws(): void
    {
        config(['analytics.breaker.threshold' => 1000]);

        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds'));
        $this->assertSame(AnalyticsClient::RETRY, $this->client()->deliver($this->payload));

        Http::fake(fn () => throw new \RuntimeException('something unexpected'));
        $this->assertSame(AnalyticsClient::RETRY, $this->client()->deliver($this->payload));
    }

    public function test_the_breaker_opens_after_repeated_failures_and_stops_calling_analytics(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(AnalyticsClient::RETRY, $this->client()->deliver($this->payload));
        }
        Http::assertSentCount(3);
        $this->assertTrue($this->client()->circuitOpen());

        $this->assertSame(AnalyticsClient::RETRY, $this->client()->deliver($this->payload));
        $this->assertSame(AnalyticsClient::RETRY, $this->client()->deliver($this->payload));
        Http::assertSentCount(3);
    }

    public function test_the_breaker_lets_one_trial_through_after_the_cooldown_and_closes_on_success(): void
    {
        Http::fake(['*' => Http::sequence()->push('x', 500)->push('x', 500)->push('x', 500)->push([], 201)]);

        for ($i = 0; $i < 3; $i++) {
            $this->client()->deliver($this->payload);
        }
        $this->assertTrue($this->client()->circuitOpen());

        Carbon::setTestNow(now()->addSeconds(301));
        $this->assertFalse($this->client()->circuitOpen());
        $this->assertSame(AnalyticsClient::DONE, $this->client()->deliver($this->payload));

        Http::assertSentCount(4);
        $this->assertFalse($this->client()->circuitOpen());
        // closed means the failure count started over: one failure no longer opens it
        Http::fake(['*' => Http::response('boom', 500)]);
        $this->client()->deliver($this->payload);
        $this->assertFalse($this->client()->circuitOpen());
    }

    public function test_a_failed_trial_after_the_cooldown_reopens_the_breaker_at_once(): void
    {
        Http::fake(['*' => Http::response('boom', 429)]);

        for ($i = 0; $i < 3; $i++) {
            $this->client()->deliver($this->payload);
        }
        Carbon::setTestNow(now()->addSeconds(301));
        $this->client()->deliver($this->payload);

        Http::assertSentCount(4);
        $this->assertTrue($this->client()->circuitOpen());
    }

    public function test_a_refusal_means_analytics_is_up_so_it_does_not_trip_the_breaker(): void
    {
        Http::fake(['*' => Http::response('no', 401)]);

        for ($i = 0; $i < 6; $i++) {
            $this->client()->deliver($this->payload);
        }

        $this->assertFalse($this->client()->circuitOpen());
        Http::assertSentCount(6);
    }

    public function test_a_broken_cache_never_breaks_delivery(): void
    {
        Cache::shouldReceive('get', 'put', 'add', 'increment', 'forget', 'has')->andThrow(new \RuntimeException('cache backend down'));
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->assertSame(AnalyticsClient::RETRY, $this->client()->deliver($this->payload));
        $this->assertFalse($this->client()->circuitOpen());
    }

    public function test_the_job_is_queued_with_three_tries_and_a_backoff(): void
    {
        $job = new SendAnalyticsSnapshot($this->payload);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff());
    }

    public function test_the_job_never_throws_whatever_the_client_does(): void
    {
        Http::fake(fn () => throw new \RuntimeException('unexpected'));

        (new SendAnalyticsSnapshot($this->payload))->handle($this->client());

        $this->mock(AnalyticsClient::class, fn ($mock) => $mock->shouldReceive('deliver')->andThrow(new \Error('even this')));
        (new SendAnalyticsSnapshot($this->payload))->handle(app(AnalyticsClient::class));

        $this->assertTrue(true, 'no exception escaped');
    }

    public function test_the_job_releases_itself_with_backoff_on_a_transient_failure_and_not_on_success_or_refusal(): void
    {
        foreach ([[AnalyticsClient::RETRY, 1, 60], [AnalyticsClient::RETRY, 2, 300], [AnalyticsClient::DONE, 1, null], [AnalyticsClient::GIVE_UP, 1, null]] as [$outcome, $attempt, $delay]) {
            $queueJob = \Mockery::mock(QueueJob::class);
            $queueJob->shouldReceive('attempts')->andReturn($attempt);
            $delay === null ? $queueJob->shouldNotReceive('release') : $queueJob->shouldReceive('release')->once()->with($delay);

            $job = new SendAnalyticsSnapshot($this->payload);
            $job->setJob($queueJob);
            $job->handle($this->stubClient($outcome));
        }

        $this->addToAssertionCount(1);
    }

    public function test_the_last_attempt_gives_up_without_releasing_or_throwing(): void
    {
        $queueJob = \Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn(3);
        $queueJob->shouldNotReceive('release');
        $job = new SendAnalyticsSnapshot($this->payload);
        $job->setJob($queueJob);

        $job->handle($this->stubClient(AnalyticsClient::RETRY));

        $this->addToAssertionCount(1);
    }

    private function stubClient(string $outcome): AnalyticsClient
    {
        return new class($outcome) extends AnalyticsClient
        {
            public function __construct(private string $outcome) {}

            public function deliver(array $payload): string
            {
                return $this->outcome;
            }
        };
    }
}
