<?php

namespace Tests\Feature\Operator;

use App\Models\PulseAuditEntry;
use App\Models\PulseBlock;
use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseEntityBlock;
use App\Models\PulseKnockBudget;
use App\Models\PulseMatch;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\PulseReport;
use App\Models\PulseReview;
use App\Models\PulseRole;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Operator\Metrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Each metric on rows seeded by hand. The range in these tests is the 30 days before 2026-11-10 12:00 UTC.
 */
class MetricsTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-11-10 12:00:00', 'UTC');
        Carbon::setTestNow($this->now);
        config(['app.timezone' => 'UTC']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function metrics(): Metrics
    {
        return app(Metrics::class);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(int $days = 30): array
    {
        return $this->metrics()->window($days, $this->now);
    }

    private function convo(array $attrs = []): PulseConversation
    {
        $created = $attrs['created_at'] ?? now()->subDays(2);
        unset($attrs['created_at']);

        $c = PulseConversation::forceCreate(array_merge([
            'type' => 'direct',
            'kind' => 'enquiry',
            'status' => 'requested',
            'initiator_id' => User::factory()->create()->id,
            'recipient_id' => User::factory()->create()->id,
            'card' => ['what' => 'Bookkeeping'],
        ], $attrs));

        DB::table('pulse_conversations')->where('id', $c->id)->update(['created_at' => $created, 'updated_at' => $created]);

        return $c->fresh();
    }

    // ---------------------------------------------------------------- nothing yet

    public function test_with_no_rows_nothing_is_dressed_as_a_result(): void
    {
        $snap = $this->metrics()->snapshot(30, $this->now);

        $this->assertNull($snap['people']['total']);
        $this->assertNull($snap['entities']);
        $this->assertNull($snap['listings']['offers']['total']);
        $this->assertNull($snap['listings']['roles']['total']);
        $this->assertNull($snap['listings']['needs']['total']);
        $this->assertNull($snap['floor']['seen']);
        $this->assertNull($snap['knocks']);
        $this->assertNull($snap['requests']['accepted']);
        $this->assertNull($snap['responses']['rate']);
        $this->assertNull($snap['responses']['median_seconds']);
        $this->assertNull($snap['outcomes']['total']);
        $this->assertNull($snap['reviews']);
        $this->assertNull($snap['reports']['opened']);
        $this->assertNull($snap['blocks']);
        $this->assertNull($snap['digests']);
        $this->assertNull($snap['exhausted']);
        $this->assertSame(0, $snap['queue']['waiting']);
        $this->assertNull($snap['queue']['oldest_seconds']);
        $this->assertNull($snap['queue']['median_seconds']);

        foreach ($this->metrics()->publishable($this->now) as $key => $value) {
            if ($key !== 'queue_waiting') {
                $this->assertNull($value, "{$key} must be null, not zero, when nothing has happened");
            }
        }
    }

    public function test_the_range_is_stated_and_clamped(): void
    {
        $snap = $this->metrics()->snapshot(30, $this->now);

        $this->assertSame(30, $snap['range']['days']);
        $this->assertTrue($snap['range']['to']->equalTo($this->now));
        $this->assertTrue($snap['range']['from']->equalTo($this->now->copy()->subDays(30)));
        $this->assertSame(365, $this->metrics()->snapshot(9999, $this->now)['range']['days']);
        $this->assertSame(1, $this->metrics()->snapshot(0, $this->now)['range']['days']);
    }

    // ---------------------------------------------------------------- people and listings

    public function test_people_by_kind_and_by_discoverability_count_only_those_who_finished_onboarding(): void
    {
        PulseProfile::factory()->count(2)->create(['kind' => 'individual', 'onboarded_at' => now()]);
        PulseProfile::factory()->discoverable('open')->create(['kind' => 'professional']);
        PulseProfile::factory()->anonymous()->create(['kind' => 'professional']);
        PulseProfile::factory()->create(['kind' => 'professional', 'onboarded_at' => null]);

        $people = $this->metrics()->people();

        $this->assertSame(4, $people['total']);
        $this->assertSame(['individual' => 2, 'professional' => 2], $people['by_kind']);
        $this->assertSame(['off' => 2, 'anonymous' => 1, 'open' => 1], $people['by_discoverability']);
    }

    public function test_active_entities_are_live_and_approved(): void
    {
        PulseEntity::factory()->count(2)->create(['status' => 'live', 'moderation' => 'approved']);
        PulseEntity::factory()->create(['status' => 'live', 'moderation' => 'held']);
        PulseEntity::factory()->create(['status' => 'suspended', 'moderation' => 'approved']);
        PulseEntity::factory()->draft()->create();

        $this->assertSame(2, $this->metrics()->activeEntities());
    }

    public function test_live_listings_by_kind_leave_out_drafts_held_and_removed(): void
    {
        PulseOffer::factory()->create(['kind' => 'service']);
        PulseOffer::factory()->create(['kind' => 'service']);
        PulseOffer::factory()->create(['kind' => 'product']);
        PulseOffer::factory()->create(['kind' => 'product', 'moderation' => 'held']);
        PulseOffer::factory()->draft()->create();
        PulseRole::factory()->create(['employment_type' => 'contract']);
        PulseRole::factory()->create(['employment_type' => 'full_time', 'status' => 'closed']);
        PulseNeed::factory()->create(['kind' => 'supplier']);
        PulseNeed::factory()->closed()->create();

        $listings = $this->metrics()->liveListings();

        $this->assertSame(3, $listings['offers']['total']);
        $this->assertSame(['product' => 1, 'service' => 2], $listings['offers']['by_kind']);
        $this->assertSame(1, $listings['roles']['total']);
        $this->assertSame(1, $listings['roles']['by_kind']['contract']);
        $this->assertSame(0, $listings['roles']['by_kind']['full_time']);
        $this->assertSame(1, $listings['needs']['total']);
        $this->assertSame(1, $listings['needs']['by_kind']['supplier']);
    }

    // ---------------------------------------------------------------- floor

    public function test_floor_rounds_count_matches_first_seen_in_the_range(): void
    {
        $signal = PulseSignal::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();

        foreach ([[$a, now()->subDays(2)], [$a, now()->subDays(3), true], [$b, now()->subDays(40)], [$b, null]] as $i => $row) {
            $signal2 = $i === 0 ? $signal : PulseSignal::factory()->create();
            PulseMatch::create(['user_id' => $row[0]->id, 'signal_id' => $signal2->id, 'score' => 50, 'reasons' => [], 'computed_at' => now(), 'seen_at' => $row[1]]);
        }

        [$from, $to] = $this->range();
        $floor = $this->metrics()->floor($from, $to);

        $this->assertSame(2, $floor['seen'], 'one seen 40 days ago and one never seen are not in this range');
        $this->assertSame(1, $floor['people']);
    }

    // ---------------------------------------------------------------- knocks and requests

    public function test_knocks_are_first_contacts_with_a_request_card_in_the_range(): void
    {
        $this->convo(['created_at' => now()->subDays(1)]);
        $this->convo(['created_at' => now()->subDays(29)]);
        $this->convo(['created_at' => now()->subDays(45)]);                       // before the range
        $this->convo(['card' => null, 'status' => 'open', 'kind' => 'dm']);       // a direct message, not a knock

        [$from, $to] = $this->range();

        $this->assertSame(2, $this->metrics()->knocks($from, $to));
    }

    public function test_requests_ended_accepted_declined_lapsed_and_still_waiting(): void
    {
        $this->convo(['status' => 'open', 'first_response_at' => now()->subDay()]);
        $this->convo(['status' => 'closed', 'first_response_at' => now()->subDay()]);                           // answered, then closed: accepted
        $this->convo(['status' => 'declined', 'first_response_at' => now()->subDay(), 'closed_reason' => 'declined']);
        $this->convo(['status' => 'closed', 'closed_reason' => 'expired']);
        $this->convo(['status' => 'requested']);
        $this->convo(['status' => 'closed', 'closed_reason' => 'withdrawn']);                                    // in none of the four
        $this->convo(['status' => 'spam', 'first_response_at' => now()->subDay()]);                              // spam is never accepted

        [$from, $to] = $this->range();
        $r = $this->metrics()->requests($from, $to);

        $this->assertSame(7, $r['sent']);
        $this->assertSame(2, $r['accepted']);
        $this->assertSame(1, $r['declined']);
        $this->assertSame(1, $r['lapsed']);
        $this->assertSame(1, $r['waiting']);
    }

    public function test_response_rate_and_median_follow_the_page_level_definition(): void
    {
        $t = now()->subDays(5);

        $this->convo(['created_at' => $t, 'status' => 'open', 'first_response_at' => $t->copy()->addHour()]);          // 3600 s
        $this->convo(['created_at' => $t, 'status' => 'open', 'first_response_at' => $t->copy()->addHours(3)]);         // 10800 s
        $this->convo(['created_at' => $t, 'status' => 'open', 'first_response_at' => $t->copy()->addHours(5)]);         // 18000 s
        $this->convo(['created_at' => $t, 'status' => 'requested']);                                                    // older than 48 h, never answered
        $this->convo(['created_at' => now()->subHours(10), 'status' => 'requested']);                                    // still inside the 48 h grace: not counted
        $this->convo(['created_at' => $t, 'status' => 'spam']);                                                          // spam is left out

        [$from, $to] = $this->range();
        $r = $this->metrics()->responses($from, $to);

        $this->assertSame(4, $r['received']);
        $this->assertSame(3, $r['answered']);
        $this->assertSame(0.75, $r['rate']);
        $this->assertSame(10800, $r['median_seconds']);
    }

    public function test_median_of_an_even_number_is_the_middle_pair(): void
    {
        $t = now()->subDays(5);
        $this->convo(['created_at' => $t, 'status' => 'open', 'first_response_at' => $t->copy()->addHour()]);
        $this->convo(['created_at' => $t, 'status' => 'open', 'first_response_at' => $t->copy()->addHours(3)]);

        [$from, $to] = $this->range();

        $this->assertSame(7200, $this->metrics()->responses($from, $to)['median_seconds']);
    }

    public function test_nothing_received_yet_is_a_null_rate_not_zero(): void
    {
        $this->convo(['created_at' => now()->subHours(3), 'status' => 'requested']); // inside the grace window

        [$from, $to] = $this->range();
        $r = $this->metrics()->responses($from, $to);

        $this->assertSame(0, $r['received']);
        $this->assertNull($r['rate']);
        $this->assertNull($r['median_seconds']);
    }

    // ---------------------------------------------------------------- outcomes

    public function test_only_outcomes_both_sides_confirmed_count_and_only_results(): void
    {
        $both = fn (string $outcome, $at) => $this->convo([
            'status' => 'open', 'outcome' => $outcome, 'outcome_side' => 'initiator',
            'outcome_initiator_at' => $at, 'outcome_recipient_at' => $at->copy()->addHour(),
        ]);

        $both('won', now()->subDays(3));
        $both('won', now()->subDays(4));
        $both('hired', now()->subDays(5));
        $both('partnered', now()->subDays(6));
        $both('lost', now()->subDays(2));                                   // confirmed, but not a result
        $both('won', now()->subDays(60));                                   // confirmed before the range
        $this->convo(['outcome' => 'won', 'outcome_side' => 'initiator', 'outcome_initiator_at' => now()->subDays(2), 'outcome_recipient_at' => null]); // proposed, not confirmed
        $this->convo(['outcome' => 'hired', 'outcome_side' => 'recipient', 'outcome_initiator_at' => null, 'outcome_recipient_at' => now()->subDays(2)]);

        [$from, $to] = $this->range();
        $o = $this->metrics()->outcomes($from, $to);

        $this->assertSame(4, $o['total']);
        $this->assertSame(['won' => 2, 'hired' => 1, 'partnered' => 1], $o['by_outcome']);
    }

    public function test_an_outcome_counts_on_the_day_the_second_side_confirmed(): void
    {
        // The first side confirmed 40 days ago, the second yesterday: it is a result of yesterday, inside the range.
        $this->convo(['status' => 'open', 'outcome' => 'won', 'outcome_side' => 'initiator',
            'outcome_initiator_at' => now()->subDays(40), 'outcome_recipient_at' => now()->subDay()]);

        [$from, $to] = $this->range();

        $this->assertSame(1, $this->metrics()->outcomes($from, $to)['total']);
    }

    // ---------------------------------------------------------------- reviews

    public function test_reviews_revealed_in_the_range_exclude_blind_held_and_removed(): void
    {
        $make = fn (array $a) => PulseReview::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'reviewable_type' => User::class,
            'reviewable_id' => User::factory()->create()->id,
            'rating' => 5, 'moderation' => 'approved', 'revealed_at' => now()->subDays(2),
        ], $a));

        $make([]);
        $make(['revealed_at' => now()->subDays(20)]);
        $make(['revealed_at' => now()->subDays(50)]);   // before the range
        $make(['revealed_at' => null]);                 // still blind
        $make(['moderation' => 'removed']);
        $make(['moderation' => 'held']);

        [$from, $to] = $this->range();

        $this->assertSame(2, $this->metrics()->reviewsRevealed($from, $to));
    }

    // ---------------------------------------------------------------- reports and the queue

    public function test_reports_opened_resolved_and_dismissed(): void
    {
        $target = User::factory()->create();
        $mk = fn (array $a) => PulseReport::create(array_merge([
            'reporter_id' => User::factory()->create()->id, 'reportable_type' => User::class,
            'reportable_id' => $target->id, 'reason' => 'spam', 'status' => 'open',
        ], $a));

        $mk([]);
        $mk(['status' => 'resolved', 'resolved_at' => now()->subDay(), 'resolution' => 'reviewed']);
        $mk(['status' => 'resolved', 'resolved_at' => now()->subDays(50), 'resolution' => 'reviewed']);
        $mk(['status' => 'dismissed', 'resolved_at' => now()->subDay(), 'resolution' => 'dismissed']);
        $old = $mk([]);
        DB::table('pulse_reports')->where('id', $old->id)->update(['created_at' => now()->subDays(80)]);

        [$from, $to] = $this->range();
        $r = $this->metrics()->reports($from, $to);

        $this->assertSame(4, $r['opened']);
        $this->assertSame(1, $r['resolved']);
        $this->assertSame(1, $r['dismissed']);
    }

    public function test_queue_age_is_measured_from_what_is_waiting_now(): void
    {
        PulsePost::factory()->create(['status' => 'flagged', 'created_at' => now()->subHours(10)]);
        $offer = PulseOffer::factory()->create(['moderation' => 'held']);
        DB::table('pulse_offers')->where('id', $offer->id)->update(['updated_at' => now()->subHours(2)]);
        PulseReport::create(['reporter_id' => User::factory()->create()->id, 'reportable_type' => User::class, 'reportable_id' => User::factory()->create()->id, 'reason' => 'spam', 'status' => 'open']);
        // Not waiting: a published post, a draft held offer, an approved offer.
        PulsePost::factory()->create(['status' => 'published']);
        PulseOffer::factory()->draft()->create(['moderation' => 'held']);

        $q = $this->metrics()->queue($this->now);

        $this->assertSame(3, $q['waiting']);
        $this->assertSame(10 * 3600, $q['oldest_seconds']);
        $this->assertSame(2 * 3600, $q['median_seconds'], 'the report was filed now (0 s), the offer 2 h ago, the post 10 h ago');
    }

    // ---------------------------------------------------------------- the rest

    public function test_blocks_count_people_and_pages_in_the_range(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $c = User::factory()->create();
        PulseBlock::create(['blocker_id' => $a->id, 'blocked_id' => $b->id]);
        $old = PulseBlock::create(['blocker_id' => $a->id, 'blocked_id' => $c->id]);
        DB::table('pulse_blocks')->where('id', $old->id)->update(['created_at' => now()->subDays(90)]);
        PulseEntityBlock::create(['entity_id' => PulseEntity::factory()->create()->id, 'user_id' => $a->id, 'side' => 'user', 'created_by' => $a->id]);

        [$from, $to] = $this->range();

        $this->assertSame(2, $this->metrics()->blocks($from, $to));
    }

    public function test_digests_sent_come_from_the_audit_log(): void
    {
        $mk = fn ($at) => PulseAuditEntry::create(['actor_id' => null, 'action' => 'pulse.digest.sent', 'created_at' => $at]);
        $mk(now()->subDays(1));
        $mk(now()->subDays(2));
        $mk(now()->subDays(100));
        PulseAuditEntry::create(['actor_id' => null, 'action' => 'line.knock', 'created_at' => now()]);

        [$from, $to] = $this->range();

        $this->assertSame(2, $this->metrics()->digestsSent($from, $to));
    }

    public function test_knock_budget_exhaustion_counts_people_with_nothing_left_this_week(): void
    {
        $established = User::factory()->create(['created_at' => now()->subDays(90)]);   // 7 a week
        $new = User::factory()->create(['created_at' => now()->subDay()]);              // 3 a week
        $penalised = User::factory()->create(['created_at' => now()->subDays(90)]);
        $stale = User::factory()->create(['created_at' => now()->subDays(90)]);

        $row = fn (User $u, array $a) => PulseKnockBudget::create(array_merge(['user_id' => $u->id, 'window_started_at' => now()->subDays(2), 'spent' => 0, 'bonus' => 0, 'penalty' => 0], $a));
        $row($established, ['spent' => 7]);                      // used all 7
        $row($new, ['spent' => 3]);                              // used all 3
        $row($penalised, ['spent' => 5, 'penalty' => 2]);        // 7 - 2 = 5 allowed, 5 spent
        $row($stale, ['spent' => 7, 'window_started_at' => now()->subDays(20)]); // an old window has already rolled over
        $row(User::factory()->create(['created_at' => now()->subDays(90)]), ['spent' => 6]);           // one left
        $row(User::factory()->create(['created_at' => now()->subDays(90)]), ['spent' => 7, 'bonus' => 1]); // a reply refilled one

        $this->assertSame(3, $this->metrics()->knockBudgetExhausted($this->now));
    }

    public function test_the_daily_series_has_every_day_so_a_quiet_day_is_a_real_zero(): void
    {
        $this->convo(['created_at' => now()->subDays(1)]);
        $this->convo(['created_at' => now()->subDays(1)->addMinutes(5)]);
        $this->convo(['created_at' => now()->subDays(3)]);

        [$from, $to] = $this->range(7);
        $series = $this->metrics()->knocksPerDay($from, $to);

        $this->assertCount(8, $series);
        $byDate = collect($series)->pluck('value', 'date');
        $this->assertSame(2, $byDate['2026-11-09']);
        $this->assertSame(1, $byDate['2026-11-07']);
        $this->assertSame(0, $byDate['2026-11-08']);
    }

    public function test_publishable_is_whole_numbers_only_and_carries_no_personal_data(): void
    {
        $person = User::factory()->create(['name' => 'Sentinel Person Name', 'email' => 'sentinel@example.test']);
        PulseProfile::factory()->discoverable('open')->create(['user_id' => $person->id, 'kind' => 'professional']);
        $this->convo(['status' => 'open', 'first_response_at' => now()->subDay(), 'created_at' => now()->subDays(3)]);

        $out = $this->metrics()->publishable($this->now);

        foreach ($out as $key => $value) {
            $this->assertTrue($value === null || is_int($value), "{$key} must be a whole number or null");
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $key);
        }

        $this->assertSame(1, $out['people_professional']);
        $this->assertSame(1, $out['people_discoverable']);
        $this->assertSame(1, $out['knocks_7d']);
        $this->assertSame(1, $out['requests_accepted_7d']);

        $json = json_encode($out);
        $this->assertStringNotContainsString('Sentinel', $json);
        $this->assertStringNotContainsString('sentinel@example.test', $json);
    }
}
