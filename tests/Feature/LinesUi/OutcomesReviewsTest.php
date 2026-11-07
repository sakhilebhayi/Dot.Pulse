<?php

namespace Tests\Feature\LinesUi;

use App\Livewire\Pulse\Lines\Thread;
use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseReview;
use App\Models\User;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\OutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class OutcomesReviewsTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: PulseEntity, 3: PulseConversation} sender, page owner, page, open enquiry line */
    private function enquiry(): array
    {
        $owner = $this->person();
        $page = $this->page($owner, ['name' => 'Moyo Plumbing', 'contact_policy' => 'requests']);
        $offer = $this->offer($page);
        $sender = $this->person(['contact_policy' => 'requests'], ['name' => 'Sipho Ndlovu']);
        $line = app(LineService::class)->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $offer);
        app(LineService::class)->accept($owner, $line);

        return [$sender, $owner, $page, $line->fresh()];
    }

    private function thread(User $as, PulseConversation $line)
    {
        return Livewire::actingAs($as)->test(Thread::class, ['conversation' => $line]);
    }

    private function outcomes(): OutcomeService
    {
        return app(OutcomeService::class);
    }

    private function words($component): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<!--.*?-->/s', '', $component->html()))));
    }

    // ------------------------------------------------------------------ the outcome bar

    public function test_an_open_line_offers_the_outcomes_that_make_sense_for_its_kind(): void
    {
        [$sender, , , $line] = $this->enquiry();

        $this->thread($sender, $line)
            ->assertSee('What did this come to?')
            ->assertSee('Won')
            ->assertSee('Not this time')
            ->assertDontSee('Hired')
            ->assertSee('Nothing counts until you both agree.');
    }

    public function test_a_job_application_offers_hired_not_won(): void
    {
        $page = $this->page(null, ['name' => 'Acme Works', 'contact_policy' => 'anyone']);
        $applicant = $this->person();
        $line = app(LineService::class)->knock($applicant, ['entity' => $page->id], 'application', $this->card(['what' => 'I would like to apply']), $this->role($page));
        app(LineService::class)->send($this->responderOf($page), $line->fresh(), 'Thanks, tell me more about you.');

        $this->thread($applicant, $line->fresh())->assertSee('Hired')->assertDontSee('Partnered');
    }

    public function test_the_bar_is_not_shown_until_the_line_has_been_answered(): void
    {
        $owner = $this->person();
        $page = $this->page($owner, ['name' => 'Moyo Plumbing', 'contact_policy' => 'requests']);
        $sender = $this->person(['contact_policy' => 'requests']);
        $line = app(LineService::class)->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $this->offer($page));

        $this->thread($sender, $line)->assertDontSee('What did this come to?');
        $this->thread($owner, $line)->assertDontSee('What did this come to?');
    }

    public function test_proposing_shows_whose_confirmation_is_awaited_and_that_it_does_not_count_yet(): void
    {
        [$sender, $owner, , $line] = $this->enquiry();

        $mine = $this->thread($sender, $line)->call('proposeOutcome', 'won')->assertDispatched('toast');
        $text = $this->words($mine);
        $this->assertStringContainsString('You marked it: Won', $text);
        $this->assertStringContainsString('Them have not confirmed yet', $text);
        $this->assertStringContainsString('It does not count yet. It counts when they confirm it.', $text);
        $this->assertStringContainsString('Change to Not this time', $text);

        $theirs = $this->words($this->thread($owner, $line->fresh()));
        $this->assertStringContainsString('They marked it: Won', $theirs);
        $this->assertStringContainsString('You have not confirmed yet', $theirs);
        $this->assertStringContainsString('It does not count until you confirm it.', $theirs);
        $this->assertStringContainsString('Yes, confirm: Won', $theirs);
        $this->assertStringContainsString('That is not right', $theirs);

        $this->assertFalse($line->fresh()->hasConfirmedOutcome());
        $this->assertSame(0, OutcomeService::confirmedCount($line->fresh()->entity));
    }

    public function test_the_proposer_cannot_confirm_their_own_outcome(): void
    {
        [$sender, , , $line] = $this->enquiry();

        $this->thread($sender, $line)->call('proposeOutcome', 'won')->call('confirmOutcome')
            ->assertSet('failure', 'The other side has to confirm your outcome.');

        $this->assertFalse($line->fresh()->hasConfirmedOutcome());
    }

    public function test_both_confirming_makes_it_count_and_the_page_says_so(): void
    {
        [$sender, $owner, , $line] = $this->enquiry();

        $this->thread($sender, $line)->call('proposeOutcome', 'won');
        $component = $this->thread($owner, $line->fresh())->call('confirmOutcome')->assertDispatched('toast');

        $this->assertTrue($line->fresh()->hasConfirmedOutcome());
        $text = $this->words($component);
        $this->assertStringContainsString('Both sides confirmed: Won. This counts as a result.', $text);
        $this->assertStringContainsString('Both sides confirmed: won (the deal happened).', $text);
        $this->assertSame(1, OutcomeService::confirmedCount($line->fresh()->entity));
    }

    public function test_not_this_time_closes_the_loop_but_is_not_counted_as_a_result(): void
    {
        [$sender, $owner, , $line] = $this->enquiry();
        $this->thread($sender, $line)->call('proposeOutcome', 'lost');

        $text = $this->words($this->thread($owner, $line->fresh())->call('confirmOutcome'));

        $this->assertStringContainsString('Both sides confirmed: Not this time. This closes the loop. It is not counted as a result.', $text);
        $this->assertSame(0, OutcomeService::confirmedCount($line->fresh()->entity));
        $this->assertStringNotContainsString('Write the review', $text);
    }

    public function test_disputing_clears_the_outcome_and_says_to_talk_it_through(): void
    {
        [$sender, $owner, , $line] = $this->enquiry();
        $this->thread($sender, $line)->call('proposeOutcome', 'won');

        $this->thread($owner, $line->fresh())->call('disputeOutcome')->assertDispatched('toast')
            ->assertSee('The last outcome was disputed and cleared. Talk it through, then mark it again.');

        $this->assertSame('none', $line->fresh()->outcome);
        $this->thread($sender, $line->fresh())->assertSee('They disputed the outcome. It was cleared.');
    }

    public function test_the_proposer_can_change_their_mind_before_the_other_side_confirms(): void
    {
        [$sender, , , $line] = $this->enquiry();

        $this->thread($sender, $line)->call('proposeOutcome', 'won')->call('proposeOutcome', 'lost');

        $this->assertSame('lost', $line->fresh()->outcome);
    }

    public function test_an_outcome_a_line_cannot_have_is_refused_in_plain_words(): void
    {
        [$sender, , , $line] = $this->enquiry();

        $this->thread($sender, $line)->call('proposeOutcome', 'hired')
            ->assertSet('failure', 'That is not something this kind of line can come to.');
    }

    public function test_a_stranger_or_editor_cannot_touch_the_outcome(): void
    {
        [, , $page, $line] = $this->enquiry();
        $editor = $this->member($page, null, 'editor');

        $this->thread($this->person(), $line)->assertNotFound();
        $this->thread($editor, $line)->assertNotFound();
    }

    public function test_a_page_teammate_sees_the_awaiting_state_for_the_page(): void
    {
        [$sender, , $page, $line] = $this->enquiry();
        $responder = $this->member($page, null, 'responder');
        $this->thread($sender, $line)->call('proposeOutcome', 'won');

        $text = $this->words($this->thread($responder, $line->fresh()));
        $this->assertStringContainsString('They marked it: Won', $text);
        $this->assertStringContainsString('Yes, confirm: Won', $text);
    }

    // ------------------------------------------------------------------ reviews

    private function confirmed(string $outcome = 'won'): array
    {
        [$sender, $owner, $page, $line] = $this->enquiry();
        $this->outcomes()->propose($sender, $line, $outcome);
        $this->outcomes()->confirm($owner, $line->fresh());

        return [$sender, $owner, $page, $line->fresh()];
    }

    public function test_the_review_form_appears_after_a_confirmed_positive_outcome_and_explains_the_blind_rule(): void
    {
        [$sender, , , $line] = $this->confirmed();

        $this->thread($sender, $line)
            ->assertSee('Reviews are written blind')
            ->assertSee('until you have both written one or 14 days pass')
            ->assertSee('Your rating')
            ->assertSee('Excellent')
            ->assertSee('Write the review')
            ->assertSee('Show me without my name');
    }

    public function test_no_review_form_before_the_outcome_is_confirmed(): void
    {
        [$sender, , , $line] = $this->enquiry();

        $this->thread($sender, $line)->assertDontSee('Write the review')->assertDontSee('Reviews are written blind');
        $this->outcomes()->propose($sender, $line, 'won');
        $this->thread($sender, $line->fresh())->assertDontSee('Write the review');
    }

    public function test_writing_a_review_keeps_it_hidden_from_the_other_side_until_they_write_theirs(): void
    {
        [$sender, $owner, $page, $line] = $this->confirmed();

        $this->thread($sender, $line)
            ->set('review.rating', 5)
            ->set('review.body', 'Fixed it in an hour and cleaned up.')
            ->call('submitReview')
            ->assertHasNoErrors()
            ->assertDispatched('toast')
            ->assertSee('Your review')
            ->assertSee('Fixed it in an hour and cleaned up.')
            ->assertSee('Written. Theirs shows with yours once they have written theirs')
            ->assertDontSee('Write the review');

        // The page owner has not written theirs: they see nothing of it.
        $this->thread($owner, $line->fresh())
            ->assertDontSee('Fixed it in an hour and cleaned up.')
            ->assertDontSee('Their review')
            ->assertSee('Write the review');

        // Nor is it public.
        $this->assertSame(0, PulseReview::query()->revealed()->count());
    }

    public function test_when_both_have_written_both_show_together_with_no_names(): void
    {
        [$sender, $owner, , $line] = $this->confirmed();

        $this->thread($sender, $line)->set('review.rating', 5)->set('review.body', 'Quick and tidy.')->call('submitReview');
        $this->thread($owner, $line->fresh())->set('review.rating', 4)->set('review.body', 'Clear brief, paid on time.')->call('submitReview');

        $mine = $this->words($this->thread($sender, $line->fresh()));
        $this->assertStringContainsString('Both of you have written one, so both are showing.', $mine);
        $this->assertStringContainsString('Your review', $mine);
        $this->assertStringContainsString('Their review', $mine);
        $this->assertStringContainsString('Clear brief, paid on time.', $mine);
        $this->assertStringContainsString('Quick and tidy.', $mine);
        $this->assertStringContainsString('4/5', $mine);
        $this->assertSame(2, PulseReview::query()->revealed()->count());
    }

    public function test_after_fourteen_days_a_lone_review_shows_to_the_other_side(): void
    {
        [$sender, $owner, , $line] = $this->confirmed();
        $this->thread($sender, $line)->set('review.rating', 3)->set('review.body', 'It was fine.')->call('submitReview');

        $this->travel(15)->days();

        $this->thread($owner, $line->fresh())->assertSee('Their review')->assertSee('It was fine.');
    }

    public function test_a_rating_is_required_and_a_link_is_not_allowed_in_a_review(): void
    {
        [$sender, , , $line] = $this->confirmed();

        $this->thread($sender, $line)
            ->call('submitReview')
            ->assertHasErrors('review.rating')
            ->assertSee('Pick a rating from 1 to 5.')
            ->set('review.rating', 4)
            ->set('review.body', 'Great, see https://example.com/promo')
            ->call('submitReview')
            ->assertHasErrors('review.body')
            ->assertSee('Leave links and contact details out of a review.');

        $this->assertSame(0, PulseReview::query()->count());
    }

    public function test_the_public_name_option_is_stored(): void
    {
        [$sender, , , $line] = $this->confirmed();

        $this->thread($sender, $line)->set('review.rating', 5)->set('review.pseudonymous', true)->call('submitReview');

        $this->assertTrue((bool) PulseReview::query()->first()->pseudonymous);
        $this->thread($sender, $line->fresh())->assertSee('Shown as a person without a name.');
    }

    public function test_a_second_review_on_the_same_line_is_not_possible(): void
    {
        [$sender, , , $line] = $this->confirmed();
        $this->thread($sender, $line)->set('review.rating', 5)->call('submitReview');

        $this->thread($sender, $line->fresh())->set('review.rating', 1)->call('submitReview')->assertSet('failure', 'You already reviewed this.');
        $this->assertSame(1, PulseReview::query()->count());
    }

    public function test_the_window_to_review_closes_and_the_page_says_so(): void
    {
        [$sender, , , $line] = $this->confirmed();
        $this->travel(61)->days();

        $this->thread($sender, $line->fresh())->assertSee('The time to write a review for this has passed.')->assertDontSee('Write the review');
    }

    public function test_a_review_that_the_rules_hold_says_a_moderator_is_reading_it(): void
    {
        [$sender, , , $line] = $this->confirmed();
        $this->thread($sender, $line)->set('review.rating', 1)->set('review.body', 'They sent a sugar daddy to the job')->call('submitReview');

        $this->thread($sender, $line->fresh())->assertSee('A moderator is reading this before it shows.');
    }

    public function test_a_review_on_an_anonymous_line_never_shows_the_professionals_name(): void
    {
        [$pro, $signal] = $this->talent('anonymous');
        $employer = $this->person([], ['name' => 'Zanele Mokoena']);
        $page = $this->page($employer, ['name' => 'Acme Works', 'is_employer' => true, 'contact_policy' => 'anyone']);
        $lines = app(LineService::class);
        $line = $lines->knock($employer, ['signal' => $signal->id, 'as_entity' => $page->id], 'invite', ['what' => 'We have a bookkeeping role open']);
        $lines->accept($pro, $line);
        $this->outcomes()->propose($employer, $line->fresh(), 'hired');
        $this->outcomes()->confirm($pro, $line->fresh());

        // The employer cannot review someone whose name they were never shown.
        $this->thread($employer, $line->fresh())->assertSee('You can review once they have shown who they are.')->assertDontSee('Write the review');

        // The professional can, and is shown without a name whatever they tick.
        $this->thread($pro, $line->fresh())
            ->assertSee('This line is anonymous, so your review is shown without your name.')
            ->set('review.rating', 5)->set('review.body', 'Clear role and a fair offer.')
            ->call('submitReview');

        $review = PulseReview::query()->first();
        $this->assertTrue((bool) $review->pseudonymous);
        $html = $this->thread($employer, $line->fresh())->html();
        $this->assertStringNotContainsString('Thandiwe', $html);
        $this->assertStringNotContainsString('Zulu-Private', $html);
    }
}
