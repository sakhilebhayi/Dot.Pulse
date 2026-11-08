<?php

namespace Tests\Feature\Operator;

use App\Livewire\Pulse\ModerationQueue;
use App\Models\Community;
use App\Models\PulseAuditEntry;
use App\Models\PulseKnockBudget;
use App\Models\PulseMessage;
use App\Models\PulseModerationLog;
use App\Models\PulseOffer;
use App\Models\PulsePost;
use App\Models\PulseReport;
use App\Models\PulseReview;
use App\Models\User;
use App\Services\Pulse\Lines\KnockBudget;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

/**
 * The v2 queue: listings, reports on lines and messages, reviews. Real rules, real services, real notifications.
 */
class QueueTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private function moderator(): User
    {
        $m = $this->person();
        \DB::table('pulse_profiles')->where('user_id', $m->id)->update(['role' => 'moderator']);

        return $m->fresh();
    }

    /** A held offer whose text the real rules flag (a shortened link). */
    private function heldOffer(array $attrs = []): PulseOffer
    {
        $owner = $this->person();
        $page = $this->page($owner, ['name' => 'Quick Fix Plumbing', 'moderation' => 'approved']);

        return $this->offer($page, array_merge([
            'title' => 'Geyser repairs this week',
            'summary' => 'Book now at bit.ly/geyser-deal and save',
            'moderation' => 'held',
        ], $attrs));
    }

    private function notices(User $user): array
    {
        return $user->notifications()->get()->map(fn ($n) => $n->data)->all();
    }

    // ---------------------------------------------------------------- listings

    public function test_the_listings_tab_shows_a_held_offer_with_the_flags_that_held_it(): void
    {
        $offer = $this->heldOffer();

        Livewire::actingAs($this->moderator())->test(ModerationQueue::class)
            ->set('tab', 'listings')
            ->assertSee('Geyser repairs this week')
            ->assertSee('Quick Fix Plumbing')
            ->assertSee('Held by the rules')
            ->assertSee('Shortened links hide where they go');
        $this->assertNotNull($offer);
    }

    public function test_approving_a_listing_makes_it_approved_and_tells_the_author(): void
    {
        $offer = $this->heldOffer();
        $author = User::find($offer->created_by);
        $moderator = $this->moderator();

        Livewire::actingAs($moderator)->test(ModerationQueue::class)
            ->set('tab', 'listings')
            ->call('approveListing', 'offer:'.$offer->id);

        $this->assertSame('approved', $offer->fresh()->moderation);
        $this->assertDatabaseHas('pulse_moderation_logs', ['target_type' => PulseOffer::class, 'target_id' => $offer->id, 'action' => 'approve', 'moderator_id' => $moderator->id]);
        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'moderation.approved', 'actor_id' => $moderator->id, 'subject_id' => $offer->id]);

        $notice = $this->notices($author)[0];
        $this->assertSame('Your offer "Geyser repairs this week" is live', $notice['title']);
        $this->assertSame('system', $notice['category']);
    }

    public function test_holding_and_removing_need_a_reason_the_author_reads(): void
    {
        $offer = $this->heldOffer();
        $author = User::find($offer->created_by);

        $queue = Livewire::actingAs($this->moderator())->test(ModerationQueue::class)
            ->set('tab', 'listings')
            ->call('promptListing', 'offer:'.$offer->id, 'removed')
            ->call('confirmListing')
            ->assertHasErrors('listingReason');

        $this->assertSame('held', $offer->fresh()->moderation, 'nothing changed without a reason');
        $this->assertSame([], $this->notices($author));

        $queue->set('listingReason', 'Shortened links hide where buyers land.')->call('confirmListing');

        $this->assertSame('removed', $offer->fresh()->moderation);
        $notice = $this->notices($author)[0];
        $this->assertStringContainsString('was removed', $notice['title']);
        $this->assertStringContainsString('Shortened links hide where buyers land.', $notice['body']);
        $this->assertDatabaseHas('pulse_moderation_logs', ['target_id' => $offer->id, 'action' => 'remove', 'rationale' => 'Shortened links hide where buyers land.']);
    }

    public function test_an_item_no_rules_checked_says_so(): void
    {
        config(['pulse.entities.rules_class' => 'Tests\\Absent\\Rules']);
        $offer = $this->heldOffer(['moderation' => 'pending', 'summary' => 'Plain text with no link']);

        Livewire::actingAs($this->moderator())->test(ModerationQueue::class)
            ->set('tab', 'listings')
            ->assertSee('Waiting, no rules ran');
        $this->assertNotNull($offer);
    }

    public function test_only_platform_moderators_touch_listings(): void
    {
        $offer = $this->heldOffer();
        $community = Community::factory()->create();
        $manager = $this->person();
        $community->addMember($manager, 'moderator');

        Livewire::actingAs($manager)->test(ModerationQueue::class)->set('tab', 'listings')->assertDontSee('Geyser repairs this week');
        Livewire::actingAs($manager)->test(ModerationQueue::class)->call('approveListing', 'offer:'.$offer->id)->assertForbidden();
        Livewire::actingAs($manager)->test(ModerationQueue::class)->call('approveClean')->assertForbidden();

        $this->assertSame('held', $offer->fresh()->moderation);
    }

    public function test_approve_clean_leaves_anything_the_rules_still_flag(): void
    {
        $flagged = $this->heldOffer();
        $clean = $this->heldOffer(['title' => 'Honest bookkeeping', 'summary' => 'Monthly books and VAT returns for small shops']);

        Livewire::actingAs($this->moderator())->test(ModerationQueue::class)
            ->set('tab', 'listings')
            ->call('approveClean');

        $this->assertSame('held', $flagged->fresh()->moderation);
        $this->assertSame('approved', $clean->fresh()->moderation);
    }

    // ---------------------------------------------------------------- reports on lines

    public function test_a_confirmed_spam_report_through_the_queue_shrinks_the_senders_budget_and_tells_both(): void
    {
        $spammer = $this->person();
        $target = $this->policy($this->person(), 'requests');
        $c = app(LineService::class)->knock($spammer, ['user' => $target->id], 'intro', $this->card());
        app(ReportService::class)->report($target, $c, 'spam', 'Selling me things');
        $before = app(KnockBudget::class)->remaining($spammer);
        $moderator = $this->moderator();

        Livewire::actingAs($moderator)->test(ModerationQueue::class)
            ->set('tab', 'lines')
            ->assertSee('Request')
            ->assertSee('Fix a leaking geyser')
            ->call('upholdReports', 'conversation', $c->id);

        $this->assertSame('resolved', PulseReport::first()->status);
        $this->assertGreaterThan(0, PulseKnockBudget::where('user_id', $spammer->id)->value('penalty'), 'ReportService::resolve ran, so the budget shrank');
        $this->assertLessThan($before, app(KnockBudget::class)->remaining($spammer));
        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'knock.shrink', 'subject_id' => $spammer->id]);
        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'report.confirm', 'actor_id' => $moderator->id]);

        $this->assertSame(1, $target->notifications()->get()->where('data.type', 'report_outcome')->count(), 'the reporter hears the outcome once');
        $told = collect($this->notices($spammer))->firstWhere('type', 'report_upheld');
        $this->assertSame('A report about your request was upheld', $told['title']);
        $this->assertStringContainsString('fewer first contacts', $told['body']);
        $this->assertStringNotContainsString($target->name, json_encode($told), 'the reporter is not named');
    }

    public function test_dismissing_a_report_does_not_shrink_anything_and_the_sender_is_not_told(): void
    {
        $sender = $this->person();
        $target = $this->policy($this->person(), 'requests');
        $c = app(LineService::class)->knock($sender, ['user' => $target->id], 'intro', $this->card());
        app(ReportService::class)->report($target, $c, 'spam', null);

        Livewire::actingAs($this->moderator())->test(ModerationQueue::class)
            ->set('tab', 'lines')
            ->call('dismissReports', 'conversation', $c->id);

        $this->assertSame('dismissed', PulseReport::first()->status);
        $this->assertSame(0, (int) PulseKnockBudget::where('user_id', $sender->id)->value('penalty'));
        $this->assertSame([], $this->notices($sender));
    }

    public function test_a_reported_message_shows_its_words_but_never_an_anonymous_professionals_name(): void
    {
        [$pro, $signal] = $this->talent('anonymous');
        $employer = $this->person();
        $page = $this->page($employer, ['is_employer' => true]);
        $lines = app(LineService::class);
        $c = $lines->knock($employer, ['signal' => $signal->id, 'as_entity' => $page->id], 'invite', $this->card());
        $lines->accept($pro, $c);
        $msg = $lines->send($pro, $c->fresh(), 'Please send me the offer details');
        app(ReportService::class)->report($employer, $msg, 'harassment', 'Unwanted');

        $html = Livewire::actingAs($this->moderator())->test(ModerationQueue::class)
            ->set('tab', 'lines')
            ->assertSee('Please send me the offer details')
            ->assertSee('kept anonymous')
            ->html();

        $this->assertStringNotContainsString('Thandiwe', $html);
        $this->assertStringNotContainsString($pro->email, $html);
        $this->assertSame(PulseMessage::class, PulseReport::first()->reportable_type);
    }

    public function test_reports_about_a_business_page_are_removed_through_the_listing_decision(): void
    {
        $owner = $this->person();
        $page = $this->page($owner, ['name' => 'Fake Fees Recruiters', 'moderation' => 'approved']);
        $reporter = $this->person();
        app(ReportService::class)->report($reporter, $page, 'spam', 'Charges a fee for jobs');

        Livewire::actingAs($this->moderator())->test(ModerationQueue::class)
            ->set('tab', 'reports')
            ->assertSee('Fake Fees Recruiters')
            ->call('promptResolve', 'entity', $page->id)
            ->call('resolveReports', 'entity', $page->id)
            ->assertHasErrors('resolveReason')
            ->set('resolveReason', 'Charges applicants a fee.')
            ->call('resolveReports', 'entity', $page->id);

        $this->assertSame('removed', $page->fresh()->moderation);
        $this->assertSame('resolved', PulseReport::first()->status);
        $this->assertStringContainsString('Charges applicants a fee.', $this->notices($owner)[0]['body']);
    }

    // ---------------------------------------------------------------- reviews

    private function heldReview(): PulseReview
    {
        $writer = $this->person();

        return PulseReview::create([
            'user_id' => $writer->id, 'reviewable_type' => User::class, 'reviewable_id' => $this->person()->id,
            'rating' => 1, 'body' => 'Held review text here', 'moderation' => 'held', 'reviewer_side' => 'initiator',
        ]);
    }

    public function test_a_held_review_can_be_approved_or_removed_with_a_reason(): void
    {
        $a = $this->heldReview();
        $b = $this->heldReview();
        $moderator = $this->moderator();

        Livewire::actingAs($moderator)->test(ModerationQueue::class)
            ->set('tab', 'lines')
            ->assertSee('Held review text here')
            ->call('approveReview', $a->id)
            ->call('promptRemoveReview', $b->id)
            ->call('confirmRemoveReview')
            ->assertHasErrors('reviewReason')
            ->set('reviewReason', 'Contains a personal attack.')
            ->call('confirmRemoveReview');

        $this->assertSame('approved', $a->fresh()->moderation);
        $this->assertSame('removed', $b->fresh()->moderation);
        $this->assertSame('Your review is live', $this->notices(User::find($a->user_id))[0]['title']);
        $this->assertStringContainsString('Contains a personal attack.', $this->notices(User::find($b->user_id))[0]['body']);
        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'review.remove', 'actor_id' => $moderator->id]);
    }

    // ---------------------------------------------------------------- posts keep working, with plain words

    public function test_approving_and_rejecting_a_post_tell_the_author_and_audit_it(): void
    {
        $approved = PulsePost::factory()->status('flagged')->create(['body' => 'A post that will be approved today.']);
        $rejected = PulsePost::factory()->status('flagged')->create(['body' => 'A post that will be rejected today.']);
        $moderator = $this->moderator();

        Livewire::actingAs($moderator)->test(ModerationQueue::class)
            ->call('approve', $approved->id)
            ->call('promptReject', $rejected->id)
            ->set('rejectReason', 'Looks like an advert.')
            ->call('confirmReject', $rejected->id);

        $this->assertSame('Your post is live', collect($this->notices($approved->author))->firstWhere('type', 'post_decision')['title']);
        $this->assertStringContainsString('Looks like an advert.', collect($this->notices($rejected->author))->firstWhere('type', 'post_decision')['body']);
        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'post.approve', 'actor_id' => $moderator->id]);
        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'post.reject', 'actor_id' => $moderator->id]);
        $this->assertSame(2, PulseModerationLog::count());
    }

    public function test_the_audit_log_stays_append_only_through_all_of_this(): void
    {
        $offer = $this->heldOffer();

        Livewire::actingAs($this->moderator())->test(ModerationQueue::class)->call('approveListing', 'offer:'.$offer->id);

        $this->assertGreaterThan(0, PulseAuditEntry::count());
        $this->expectException(\LogicException::class);
        PulseAuditEntry::first()->update(['action' => 'changed']);
    }
}
