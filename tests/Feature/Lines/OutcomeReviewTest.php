<?php

namespace Tests\Feature\Lines;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseReview;
use App\Models\User;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\OutcomeService;
use App\Services\Pulse\Lines\ReviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class OutcomeReviewTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private function outcomes(): OutcomeService
    {
        return app(OutcomeService::class);
    }

    private function reviews(): ReviewService
    {
        return app(ReviewService::class);
    }

    /** @return array{0: User, 1: PulseEntity, 2: User, 3: PulseConversation} */
    private function enquiryLine(): array
    {
        $customer = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests', 'name' => 'Moyo Plumbing']);
        $owner = $this->responderOf($page);
        $c = $this->openLine($customer, $page);

        return [$customer, $page, $owner, $c];
    }

    public function test_only_both_sides_confirming_counts(): void
    {
        [$customer, $page, $owner, $c] = $this->enquiryLine();

        $this->assertFalse(OutcomeService::confirmed($c));

        $this->outcomes()->propose($customer, $c, 'won');
        $c->refresh();
        $this->assertSame('won', $c->outcome);
        $this->assertFalse(OutcomeService::confirmed($c), 'one side is not enough');
        $this->assertSame(0, PulseConversation::query()->confirmed()->count());
        $this->assertSame(0, OutcomeService::confirmedCount($page));

        $this->outcomes()->confirm($owner, $c);
        $c->refresh();

        $this->assertTrue(OutcomeService::confirmed($c));
        $this->assertTrue(OutcomeService::successful($c));
        $this->assertSame(1, PulseConversation::query()->confirmed()->count());
        $this->assertSame(1, PulseConversation::query()->successful()->count());
        $this->assertSame(1, OutcomeService::confirmedCount($page));
        $this->assertSame(1, $customer->notifications()->where('data', 'like', '%outcome_confirmed%')->count());
        $this->assertSame(1, $owner->notifications()->where('data', 'like', '%outcome_confirmed%')->count());
    }

    public function test_the_proposer_cannot_confirm_their_own_proposal_and_a_stranger_cannot_touch_it(): void
    {
        [$customer, $page, $owner, $c] = $this->enquiryLine();
        $this->outcomes()->propose($owner, $c, 'won');

        try {
            $this->outcomes()->confirm($owner, $c->fresh());
            $this->fail('the other side has to confirm');
        } catch (LineException $e) {
            $this->assertSame('not_allowed', $e->reason);
        }

        foreach ([$this->person(), $this->member($page, null, 'editor')] as $nope) {
            foreach (['confirm' => [], 'dispute' => [], 'propose' => ['lost']] as $method => $args) {
                try {
                    $this->outcomes()->{$method}($nope, $c->fresh(), ...$args);
                    $this->fail("{$method} should be refused to a non-participant");
                } catch (AuthorizationException) {
                }
            }
        }

        $this->assertFalse(OutcomeService::confirmed($c->fresh()));
    }

    public function test_a_new_proposal_resets_the_other_sides_confirmation(): void
    {
        [$customer, , $owner, $c] = $this->enquiryLine();
        $this->outcomes()->propose($customer, $c, 'won');
        $this->outcomes()->propose($owner, $c->fresh(), 'lost'); // the other side says something different

        $c->refresh();
        $this->assertSame('lost', $c->outcome);
        $this->assertSame('recipient', $c->outcome_side);
        $this->assertNull($c->outcome_initiator_at, 'the first proposer has not agreed to the new outcome');
        $this->assertFalse(OutcomeService::confirmed($c));
    }

    public function test_dispute_clears_it_and_records_why_nothing_counts(): void
    {
        [$customer, , $owner, $c] = $this->enquiryLine();
        $this->outcomes()->propose($customer, $c, 'won');

        try {
            $this->outcomes()->dispute($customer, $c->fresh());
            $this->fail('cannot dispute your own proposal');
        } catch (LineException) {
        }

        $this->outcomes()->dispute($owner, $c->fresh());

        $c->refresh();
        $this->assertSame('none', $c->outcome);
        $this->assertNull($c->outcome_initiator_at);
        $this->assertNotNull($c->outcome_disputed_at);
        $this->assertSame(1, $customer->notifications()->where('data', 'like', '%outcome_disputed%')->count());
        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'line.outcome_dispute', 'actor_id' => $owner->id]);

        $this->expectException(LineException::class);
        $this->outcomes()->confirm($owner, $c->fresh());
    }

    public function test_a_confirmed_outcome_is_final_and_each_kind_has_its_own_outcomes(): void
    {
        [$customer, , $owner, $c] = $this->enquiryLine();

        foreach (['hired', 'partnered', 'bogus'] as $wrong) {
            try {
                $this->outcomes()->propose($customer, $c, $wrong);
                $this->fail("{$wrong} is not an outcome of an enquiry");
            } catch (LineException $e) {
                $this->assertSame('invalid', $e->reason);
            }
        }

        $this->outcomes()->propose($customer, $c, 'won');
        $this->outcomes()->confirm($owner, $c->fresh());

        $this->expectException(LineException::class);
        $this->outcomes()->propose($owner, $c->fresh(), 'lost');
    }

    public function test_a_line_nobody_answered_has_no_outcome(): void
    {
        $customer = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $c = app(LineService::class)->knock($customer, ['entity' => $page->id], 'enquiry', $this->card());

        $this->expectException(AuthorizationException::class);
        $this->outcomes()->propose($customer, $c, 'won');
    }

    public function test_a_confirmed_lost_closes_the_loop_but_is_not_a_result(): void
    {
        [$customer, $page, $owner, $c] = $this->enquiryLine();
        $this->outcomes()->propose($owner, $c, 'lost');
        $this->outcomes()->confirm($customer, $c->fresh());
        $c->refresh();

        $this->assertTrue(OutcomeService::confirmed($c));
        $this->assertFalse(OutcomeService::successful($c));
        $this->assertSame(0, OutcomeService::confirmedCount($page));
        $this->assertNull($this->reviews()->canReview($customer, $page));
    }

    public function test_application_and_collab_outcomes(): void
    {
        $applicant = $this->person();
        $employer = $this->page(null, ['is_employer' => true, 'contact_policy' => 'requests']);
        $role = $this->role($employer);
        $c = app(LineService::class)->knock($applicant, ['entity' => $employer->id], 'application', $this->card(['what' => 'Applying for the bookkeeper role']), $role);
        app(LineService::class)->accept($this->responderOf($employer), $c);

        $this->outcomes()->propose($this->responderOf($employer), $c->fresh(), 'hired');
        $this->outcomes()->confirm($applicant, $c->fresh());

        $this->assertTrue(OutcomeService::successful($c->fresh()));
    }

    // ------------------------------------------------------------------- reviews

    private function confirmedLine(): array
    {
        [$customer, $page, $owner, $c] = $this->enquiryLine();
        $this->outcomes()->propose($customer, $c, 'won');
        $this->outcomes()->confirm($owner, $c->fresh());

        return [$customer, $page, $owner, $c->fresh()];
    }

    public function test_no_review_without_a_confirmed_result(): void
    {
        [$customer, $page, $owner, $c] = $this->enquiryLine();

        $this->assertNull($this->reviews()->canReview($customer, $page));

        try {
            $this->reviews()->submit($customer, $c, ['rating' => 5, 'body' => 'Lovely']);
            $this->fail('no confirmed outcome');
        } catch (LineException $e) {
            $this->assertSame('not_allowed', $e->reason);
        }

        $this->outcomes()->propose($customer, $c, 'won');
        $this->assertNull($this->reviews()->canReview($customer, $page), 'proposed but not confirmed');
    }

    public function test_after_a_confirmed_result_both_sides_may_review_once(): void
    {
        [$customer, $page, $owner, $c] = $this->confirmedLine();

        $this->assertSame($c->id, $this->reviews()->canReview($customer, $page)?->id);
        $this->assertSame($c->id, $this->reviews()->canReview($owner, $customer)?->id, 'the page can review the customer too');
        $this->assertNull($this->reviews()->canReview($this->person(), $page));

        $r = $this->reviews()->submit($customer, $c, ['rating' => 5, 'body' => 'Fixed the geyser in an hour.']);

        $this->assertSame($page->id, $r->reviewable_id);
        $this->assertSame(PulseReview::class, $r::class);
        $this->assertTrue($r->is_verified);
        $this->assertSame('won', $r->outcome);
        $this->assertNull($this->reviews()->canReview($customer, $page), 'one per person per line');

        try {
            $this->reviews()->submit($customer, $c->fresh(), ['rating' => 1]);
            $this->fail('second review by the same person');
        } catch (LineException $e) {
            $this->assertSame('exists', $e->reason);
        }
    }

    public function test_reviews_are_blind_until_both_submit_then_revealed_together(): void
    {
        [$customer, $page, $owner, $c] = $this->confirmedLine();

        $first = $this->reviews()->submit($customer, $c, ['rating' => 4, 'body' => 'Good work.']);
        $this->assertNull($first->revealed_at);
        $this->assertCount(0, $this->reviews()->forSubject($page), 'hidden from the public');
        $this->assertSame(['count' => 0, 'average' => null], $this->reviews()->summary($page));
        $this->assertCount(1, $this->reviews()->mine($customer), 'but the author sees their own');
        $this->assertSame(1, $owner->notifications()->where('data', 'like', '%review_waiting%')->count());

        $second = $this->reviews()->submit($owner, $c->fresh(), ['rating' => 5, 'body' => 'Easy customer.']);

        $this->assertNotNull($first->fresh()->revealed_at);
        $this->assertNotNull($second->revealed_at);
        $this->assertCount(1, $this->reviews()->forSubject($page));
        $this->assertCount(1, $this->reviews()->forSubject($customer));
        $this->assertSame(['count' => 1, 'average' => 4.0], $this->reviews()->summary($page));
        $this->assertSame(1, $customer->notifications()->where('data', 'like', '%review_revealed%')->count());
    }

    public function test_a_lone_review_is_revealed_after_fourteen_days_by_the_sweep_and_even_before_it_runs(): void
    {
        [$customer, $page, , $c] = $this->confirmedLine();
        $this->reviews()->submit($customer, $c, ['rating' => 3, 'body' => 'Fine.']);

        $this->travel(13)->days();
        $this->assertCount(0, $this->reviews()->forSubject($page));

        $this->travel(2)->days();
        $this->assertCount(1, $this->reviews()->forSubject($page), 'public by the date alone');

        $this->assertSame(1, $this->reviews()->revealDue());
        $this->assertSame(0, $this->reviews()->revealDue());
    }

    public function test_pseudonymous_reviews_show_no_name_and_named_ones_show_a_short_name(): void
    {
        [$customer, $page, $owner, $c] = $this->confirmedLine();
        $customer->forceFill(['name' => 'Naledi Mokoena'])->save();

        $this->reviews()->submit($customer, $c, ['rating' => 5, 'body' => 'Great.', 'pseudonymous' => true]);
        $this->reviews()->submit($owner, $c->fresh(), ['rating' => 5, 'body' => 'Great customer.']);

        $onPage = $this->reviews()->forSubject($page)->first();
        $this->assertSame('A customer', $onPage['author']);
        $this->assertStringNotContainsString('Naledi', json_encode($onPage));

        $onCustomer = $this->reviews()->forSubject($customer)->first();
        $this->assertNotSame('A customer', $onCustomer['author']);
    }

    public function test_named_review_uses_first_name_and_last_initial(): void
    {
        [$customer, $page, $owner, $c] = $this->confirmedLine();
        $customer->forceFill(['name' => 'Naledi Mokoena'])->save();
        $this->reviews()->submit($customer, $c, ['rating' => 5]);
        $this->reviews()->submit($owner, $c->fresh(), ['rating' => 5]);

        $this->assertSame('Naledi M.', $this->reviews()->forSubject($page)->first()['author']);
    }

    public function test_review_input_is_validated_and_links_are_refused(): void
    {
        [$customer, , , $c] = $this->confirmedLine();

        foreach ([0, 6, null] as $rating) {
            try {
                $this->reviews()->submit($customer, $c, ['rating' => $rating]);
                $this->fail('bad rating');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('rating', $e->errors());
            }
        }

        $this->expectException(ValidationException::class);
        $this->reviews()->submit($customer, $c, ['rating' => 5, 'body' => 'Visit www.better-plumber.co.za instead']);
    }

    public function test_only_people_on_the_line_can_review_it(): void
    {
        [, , , $c] = $this->confirmedLine();

        $this->expectException(AuthorizationException::class);
        $this->reviews()->submit($this->person(), $c, ['rating' => 1, 'body' => 'drive by']);
    }

    public function test_the_review_window_closes(): void
    {
        [$customer, $page, , $c] = $this->confirmedLine();
        $this->travel(61)->days();

        $this->assertNull($this->reviews()->canReview($customer, $page));
        $this->expectException(LineException::class);
        $this->reviews()->submit($customer, $c, ['rating' => 5]);
    }

    public function test_a_held_review_is_invisible_until_a_moderator_approves_it(): void
    {
        [$customer, $page, $owner, $c] = $this->confirmedLine();
        $r = $this->reviews()->submit($customer, $c, ['rating' => 5, 'body' => 'Great.']);
        $this->reviews()->submit($owner, $c->fresh(), ['rating' => 5]);
        $r->forceFill(['moderation' => 'held'])->save();

        $this->assertCount(0, $this->reviews()->forSubject($page));

        try {
            $this->reviews()->moderate($customer, $r, true);
            $this->fail('moderators only');
        } catch (AuthorizationException) {
        }

        $moderator = $this->person(['role' => 'moderator']);
        DB::table('pulse_profiles')->where('user_id', $moderator->id)->update(['role' => 'moderator']);
        $this->reviews()->moderate($moderator->fresh(), $r, true);

        $this->assertCount(1, $this->reviews()->forSubject($page));
    }

    public function test_the_database_allows_one_review_per_person_per_line(): void
    {
        [$customer, $page, , $c] = $this->confirmedLine();
        $this->reviews()->submit($customer, $c, ['rating' => 5]);

        $this->expectException(UniqueConstraintViolationException::class);
        PulseReview::create(['user_id' => $customer->id, 'reviewable_type' => $page::class, 'reviewable_id' => $page->id, 'rating' => 1, 'conversation_id' => $c->id]);
    }
}
