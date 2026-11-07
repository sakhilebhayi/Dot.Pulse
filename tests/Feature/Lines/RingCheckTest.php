<?php

namespace Tests\Feature\Lines;

use App\Livewire\Pulse\ModerationQueue;
use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseProfile;
use App\Models\PulseReview;
use App\Models\User;
use App\Services\Pulse\Entities\EntityService;
use App\Services\Pulse\Lines\OutcomeService;
use App\Services\Pulse\Lines\ReviewService;
use App\Services\Pulse\Operator\Metrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

/** Two accounts confirming each other's results again and again: accepted, flagged, kept out of counts, decided by a person. */
class RingCheckTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private User $buyer;

    private PulseEntity $page;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = $this->old($this->person());
        $this->page = $this->page($this->old($this->person()), ['contact_policy' => 'requests', 'name' => 'Moyo Plumbing']);
        $this->seller = $this->responderOf($this->page);
        $this->old($this->seller);
    }

    /** An account older than the new account window, so only pair frequency is in play. */
    private function old(User $u): User
    {
        $u->forceFill(['created_at' => now()->subDays(60)])->save();

        return $u;
    }

    /** One more confirmed win between the buyer and the page. */
    private function win(?User $buyer = null, ?PulseEntity $page = null): PulseConversation
    {
        $buyer ??= $this->buyer;
        $page ??= $this->page;
        $c = $this->answered($this->openLine($buyer, $page));
        app(OutcomeService::class)->propose($buyer, $c, 'won');
        app(OutcomeService::class)->confirm($this->responderOf($page), $c->fresh());
        $this->closeLine($c);

        return $c->fresh();
    }

    /** Once two have talked, a new line between them opens straight away; give it the reply an outcome needs. */
    private function answered(PulseConversation $c): PulseConversation
    {
        if ($c->first_response_at === null) {
            DB::table('pulse_conversations')->where('id', $c->id)->update(['first_response_at' => now()]);
        }

        return $c->fresh();
    }

    /** A finished line is closed, so the next knock between the same two opens a new one. */
    private function closeLine(PulseConversation $c): void
    {
        DB::table('pulse_conversations')->where('id', $c->id)->update(['status' => 'closed', 'closed_reason' => 'outcome', 'closed_at' => now()]);
    }

    private function moderator(): User
    {
        $m = $this->old($this->person());
        PulseProfile::query()->where('user_id', $m->id)->update(['role' => 'moderator']);

        return $m->fresh();
    }

    public function test_the_fourth_result_between_the_same_two_is_accepted_but_flagged_and_left_out_of_counts(): void
    {
        foreach (range(1, 3) as $i) {
            $this->assertFalse($this->win()->needs_review, "result {$i} is fine");
        }

        $this->assertSame(3, OutcomeService::confirmedCount($this->page));

        $fourth = $this->win();

        $this->assertTrue($fourth->needs_review);
        $this->assertSame('pair_frequency', $fourth->needs_review_reason);
        $this->assertTrue(OutcomeService::confirmed($fourth), 'the two people still see it as confirmed');
        $this->assertSame(3, OutcomeService::confirmedCount($this->page), 'but it is not counted for the page');
        $this->assertSame(3, PulseConversation::query()->successful()->count());
        $this->assertSame(3, app(EntityService::class)->stats($this->page)['confirmed_outcomes']);
        $this->assertSame(3, app(Metrics::class)->outcomes(now()->subDay(), now()->addDay())['total']);
        $this->assertSame(4, $this->buyer->notifications()->where('data', 'like', '%outcome_confirmed%')->count(), 'the flagged one was still told to them as confirmed');
        $this->assertTrue(DB::table('pulse_audit_log')->where('action', 'line.ring_flag')->exists());
    }

    public function test_a_different_pair_is_not_affected_and_old_results_fall_out_of_the_window(): void
    {
        foreach (range(1, 3) as $i) {
            $this->win();
        }

        $other = $this->old($this->person());
        $this->assertFalse($this->win($other)->needs_review, 'another customer with the same page is another pair');

        DB::table('pulse_conversations')->where('outcome', 'won')->where('initiator_id', $this->buyer->id)->update([
            'outcome_initiator_at' => now()->subDays(100), 'outcome_recipient_at' => now()->subDays(100),
        ]);

        $this->assertFalse($this->win()->needs_review, 'results older than 90 days do not count towards the pair');
    }

    public function test_a_brand_new_account_pair_is_flagged_on_the_second_result(): void
    {
        $fresh = $this->person();
        $fresh->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->assertFalse($this->win($fresh)->needs_review);
        $second = $this->win($fresh);

        $this->assertTrue($second->needs_review);
        $this->assertSame('new_account_pair', $second->needs_review_reason);
    }

    public function test_a_page_and_a_person_hiring_each_other_repeatedly_is_one_pair_whichever_account_of_the_page_acts(): void
    {
        $employer = $this->page($this->old($this->person()), ['contact_policy' => 'requests', 'name' => 'Karoo Logistics', 'is_employer' => true]);
        $second = $this->old($this->member($employer, null, 'admin'));
        [$pro] = $this->talent('open');
        $this->old($pro);
        $lines = app(\App\Services\Pulse\Lines\LineService::class);

        $flags = [];

        foreach (range(1, 4) as $i) {
            $recruiter = $i % 2 ? $this->responderOf($employer) : $second;
            $signal = \App\Models\PulseSignal::query()->where('owner_id', $pro->id)->where('kind', 'talent')->first();
            $c = $lines->knock($recruiter, ['signal' => $signal->id, 'as_entity' => $employer->id], 'invite', $this->card(['what' => 'We would like to talk about role number '.$i]));

            if ($c->status === PulseConversation::REQUESTED) {
                $lines->accept($pro, $c);
            }

            $c = $this->answered($c->fresh());

            app(OutcomeService::class)->propose($recruiter, $c->fresh(), 'hired');
            app(OutcomeService::class)->confirm($pro, $c->fresh());
            $flags[] = (bool) $c->fresh()->needs_review;
            $this->closeLine($c);
        }

        $this->assertSame([false, false, false, true], $flags, 'the page and the professional are one pair, whoever on the page sends the invitation');
    }

    public function test_reviews_on_a_flagged_result_are_held_and_stay_out_of_the_public_summary(): void
    {
        foreach (range(1, 3) as $i) {
            $this->win();
        }
        $flagged = $this->win();

        $this->assertNotNull(app(ReviewService::class)->canReview($this->buyer, $this->page), 'the buyer can still write a review');
        $review = app(ReviewService::class)->submit($this->buyer, $flagged, ['rating' => 5, 'body' => 'Great work on the geyser']);

        $this->assertSame('held', $review->moderation);
        $this->assertTrue($review->held_for_ring);
        $this->assertSame(0, app(ReviewService::class)->summary($this->page)['count']);
    }

    public function test_the_moderation_queue_shows_the_flagged_result_and_approve_releases_it_and_its_reviews(): void
    {
        foreach (range(1, 3) as $i) {
            $this->win();
        }
        $flagged = $this->win();
        app(ReviewService::class)->submit($this->buyer, $flagged, ['rating' => 5, 'body' => 'Great work on the geyser']);
        app(ReviewService::class)->submit($this->seller, $flagged, ['rating' => 4, 'body' => 'Easy customer to deal with']);
        $moderator = $this->moderator();

        $queue = Livewire::actingAs($moderator)->test(ModerationQueue::class)->set('tab', 'lines')
            ->assertSee('Repeated pair')->assertSee('Moyo Plumbing')->assertSee('Approve')->assertSee('2 reviews are held with it');

        $queue->call('approveResult', $flagged->id)->assertSee('Approved');

        $flagged->refresh();
        $this->assertFalse($flagged->needs_review);
        $this->assertNotNull($flagged->review_cleared_at);
        $this->assertSame(4, OutcomeService::confirmedCount($this->page), 'it counts now');
        $this->assertSame(2, PulseReview::where('conversation_id', $flagged->id)->where('moderation', 'approved')->count());
        $this->assertTrue(DB::table('pulse_audit_log')->where('action', 'line.ring_approve')->whereNotNull('actor_id')->exists());

        // The pair's limit starts again: the next result is not flagged.
        $this->assertFalse($this->win()->needs_review);
    }

    public function test_discard_clears_the_outcome_removes_its_reviews_and_lets_the_line_be_marked_again(): void
    {
        foreach (range(1, 3) as $i) {
            $this->win();
        }
        $flagged = $this->win();
        $review = app(ReviewService::class)->submit($this->buyer, $flagged, ['rating' => 5, 'body' => 'Great work on the geyser']);

        Livewire::actingAs($this->moderator())->test(ModerationQueue::class)->set('tab', 'lines')->call('discardResult', $flagged->id)->assertSee('Discarded');

        $flagged->refresh();
        $this->assertSame('none', $flagged->outcome);
        $this->assertFalse($flagged->needs_review);
        $this->assertSame(3, OutcomeService::confirmedCount($this->page));
        $this->assertSame('removed', $review->fresh()->moderation);
        $this->assertSame(0, PulseReview::query()->revealed()->count());
        $this->assertTrue(DB::table('pulse_audit_log')->where('action', 'line.ring_discard')->exists());
    }

    public function test_only_a_platform_moderator_can_decide(): void
    {
        foreach (range(1, 3) as $i) {
            $this->win();
        }
        $flagged = $this->win();

        Livewire::actingAs($this->old($this->person()))->test(ModerationQueue::class)->assertForbidden();

        $community = \App\Models\Community::factory()->create();
        $communityMod = $this->old($this->person());
        $community->addMember($communityMod, 'moderator');
        Livewire::actingAs($communityMod)->test(ModerationQueue::class)->call('approveResult', $flagged->id)->assertForbidden();
        $this->assertTrue($flagged->fresh()->needs_review);
    }
}
