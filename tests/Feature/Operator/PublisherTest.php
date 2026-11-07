<?php

namespace Tests\Feature\Operator;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Dot.Analytics publisher with the operator's aggregates added. The three catalogue metrics keep their keys and
 * order; the extra keys are whole numbers; nothing personal is in the body; switching it off restores the old body.
 */
class PublisherTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://analytics.test/api/ingest/snapshots';

    private const SECRET = 'analytics-ingest-secret-for-tests-0001';

    private const COMPANY = '5b0f2d3e-8a41-4c7e-9d62-3f1a7c9e0b44';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:30:00');
        Cache::flush();
        config(['analytics' => [
            'url' => self::URL, 'secret' => self::SECRET, 'platform' => 'pulse', 'company_uuid' => self::COMPANY,
            'timeout' => 5, 'breaker' => ['threshold' => 3, 'cooldown' => 300], 'extended' => true,
        ]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedRows(): void
    {
        PulsePost::factory()->create(['status' => 'published', 'published_at' => now()->subDay()]);
        PulseProfile::factory()->discoverable('anonymous')->create(['kind' => 'professional']);
        PulseEntity::factory()->create();

        $c = PulseConversation::forceCreate([
            'type' => 'direct', 'kind' => 'enquiry', 'status' => 'open', 'card' => ['what' => 'x'],
            'initiator_id' => User::factory()->create()->id, 'recipient_id' => User::factory()->create()->id,
            'first_response_at' => now()->subDay(), 'outcome' => 'won', 'outcome_side' => 'initiator',
            'outcome_initiator_at' => now()->subDays(2), 'outcome_recipient_at' => now()->subDays(1),
        ]);
        DB::table('pulse_conversations')->where('id', $c->id)->update(['created_at' => now()->subDays(3)]);
    }

    public function test_the_core_three_come_first_unchanged_and_the_extras_follow(): void
    {
        Http::fake([self::URL => Http::response(['status' => 'stored'], 201)]);
        $this->seedRows();

        $this->artisan('pulse:publish-metrics')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $body = $request->body();
            $json = json_decode($body, true);
            $timestamp = $request->header('X-InfoDot-Timestamp')[0] ?? '';
            $metrics = $json['metrics'];

            return ($request->header('X-InfoDot-Signature')[0] ?? '') === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET)
                && array_keys($json) === ['platform', 'company_uuid', 'taken_at', 'period', 'metrics']
                && array_slice(array_keys($metrics), 0, 3) === ['posts_7d', 'active_users_7d', 'held_posts']
                && array_slice($metrics, 0, 3) === ['posts_7d' => 1, 'active_users_7d' => 1, 'held_posts' => 0]
                && $metrics['people_professional'] === 1
                && $metrics['people_discoverable'] === 1
                && $metrics['entities_live'] === 1
                && $metrics['knocks_7d'] === 1
                && $metrics['requests_accepted_7d'] === 1
                && $metrics['outcomes_won_7d'] === 1
                && strlen($body) < 2000;
        });
    }

    public function test_every_extra_value_is_a_whole_number_and_no_data_is_left_out_not_zeroed(): void
    {
        Http::fake();
        $this->seedRows();

        Artisan::call('pulse:publish-metrics', ['--dry-run' => true]);
        preg_match('/\{.*\}/s', Artisan::output(), $m);
        $metrics = json_decode($m[0], true)['metrics'];

        foreach ($metrics as $key => $value) {
            $this->assertIsInt($value, "{$key} must be a whole number");
            $this->assertGreaterThanOrEqual(0, $value);
        }

        $this->assertArrayNotHasKey('reviews_revealed_7d', $metrics, 'no review has ever existed, so it is not reported');
        $this->assertArrayNotHasKey('reports_opened_7d', $metrics);
        $this->assertArrayNotHasKey('blocks_7d', $metrics);
    }

    public function test_switching_the_extras_off_restores_the_original_body(): void
    {
        config(['analytics.extended' => false]);
        Http::fake([self::URL => Http::response([], 201)]);
        $this->seedRows();

        $this->artisan('pulse:publish-metrics')->assertSuccessful();

        Http::assertSent(fn (Request $r) => array_keys(json_decode($r->body(), true)['metrics']) === ['posts_7d', 'active_users_7d', 'held_posts']);
    }

    public function test_no_personal_data_reaches_the_body(): void
    {
        Http::fake([self::URL => Http::response([], 201)]);
        $person = User::factory()->create(['name' => 'Sentinel Operator Person', 'email' => 'operator-sentinel@example.test']);
        PulseProfile::factory()->discoverable('open')->create(['user_id' => $person->id, 'headline' => 'Sentinel headline text']);
        $entity = PulseEntity::factory()->create(['name' => 'Sentinel Trading Name']);
        $this->seedRows();

        $this->artisan('pulse:publish-metrics')->assertSuccessful();

        Http::assertSent(function (Request $r) use ($person, $entity) {
            $body = $r->body();

            return ! str_contains($body, 'Sentinel')
                && ! str_contains($body, $person->email)
                && ! str_contains($body, $entity->slug);
        });
    }

    public function test_a_failure_in_the_extras_cannot_stop_the_core_snapshot(): void
    {
        Http::fake([self::URL => Http::response([], 201)]);
        $this->seedRows();
        Schema::drop('pulse_knock_budgets');

        $this->artisan('pulse:publish-metrics')->assertSuccessful();

        Http::assertSent(fn (Request $r) => array_keys(json_decode($r->body(), true)['metrics']) === ['posts_7d', 'active_users_7d', 'held_posts']);
    }

    public function test_the_publisher_stays_inert_until_the_environment_is_set(): void
    {
        Http::fake();
        config(['analytics.url' => '', 'analytics.secret' => '', 'analytics.company_uuid' => '']);
        $this->seedRows();

        $this->artisan('pulse:publish-metrics')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_refusal_from_analytics_gives_up_quietly(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'invalid_request'], 422)]);
        $this->seedRows();

        $this->artisan('pulse:publish-metrics')->assertSuccessful();

        Http::assertSentCount(1);
    }
}
