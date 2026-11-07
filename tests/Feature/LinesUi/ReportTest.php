<?php

namespace Tests\Feature\LinesUi;

use App\Livewire\Pulse\Lines\Report;
use App\Livewire\Pulse\Lines\Thread;
use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseMessage;
use App\Models\PulseReport;
use App\Models\PulseReview;
use App\Models\User;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\OutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: PulseEntity, 3: PulseConversation} */
    private function line(): array
    {
        $owner = $this->person();
        $page = $this->page($owner, ['name' => 'Moyo Plumbing', 'contact_policy' => 'requests']);
        $sender = $this->person(['contact_policy' => 'requests']);
        $line = app(LineService::class)->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $this->offer($page));
        app(LineService::class)->accept($owner, $line);

        return [$sender, $owner, $page, $line->fresh()];
    }

    public function test_a_participant_reports_a_line_with_a_reason_and_it_closes_for_them(): void
    {
        [$sender, , , $line] = $this->line();

        Livewire::actingAs($sender)->test(Report::class, ['subjectType' => 'conversation', 'subjectId' => $line->id])
            ->assertSee('Report')
            ->call('toggle')
            ->assertSee('What is wrong with this line?')
            ->assertSee('Spam or scam')
            ->assertSee('Moderators read this. They do not see your name')
            ->set('reason', 'spam')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('sent', true)
            ->assertSee('Reported. A moderator will look.')
            ->assertDispatched('line-reported')
            ->assertDispatched('toast');

        $report = PulseReport::query()->first();
        $this->assertSame($sender->id, $report->reporter_id);
        $this->assertSame(PulseConversation::class, $report->reportable_type);
        $this->assertSame('closed', $line->fresh()->status);
    }

    public function test_a_reason_is_required_and_other_needs_a_note(): void
    {
        [$sender, , , $line] = $this->line();

        Livewire::actingAs($sender)->test(Report::class, ['subjectType' => 'conversation', 'subjectId' => $line->id])
            ->call('toggle')
            ->call('submit')
            ->assertHasErrors('reason')
            ->assertSee('Pick the closest reason.')
            ->set('reason', 'other')
            ->call('submit')
            ->assertHasErrors('note')
            ->assertSee('Tell us a little about what is wrong.')
            ->set('note', 'They keep asking me to pay a deposit by EFT first')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame('They keep asking me to pay a deposit by EFT first', PulseReport::query()->first()->details);
    }

    public function test_reporting_twice_shows_reported_and_does_not_double_file(): void
    {
        [$sender, , , $line] = $this->line();

        Livewire::actingAs($sender)->test(Report::class, ['subjectType' => 'conversation', 'subjectId' => $line->id])
            ->call('toggle')->set('reason', 'harassment')->call('submit');

        Livewire::actingAs($sender)->test(Report::class, ['subjectType' => 'conversation', 'subjectId' => $line->id])
            ->assertSet('sent', true)
            ->assertSee('Reported. A moderator will look.');

        $this->assertSame(1, PulseReport::query()->count());
    }

    public function test_a_stranger_cannot_report_a_line_they_are_not_on(): void
    {
        [, , , $line] = $this->line();

        Livewire::actingAs($this->person())->test(Report::class, ['subjectType' => 'conversation', 'subjectId' => $line->id])->assertNotFound();
        $this->assertSame(0, PulseReport::query()->count());
    }

    public function test_unknown_subject_types_and_missing_subjects_are_a_404(): void
    {
        $user = $this->person();

        Livewire::actingAs($user)->test(Report::class, ['subjectType' => 'nonsense', 'subjectId' => 1])->assertNotFound();
        Livewire::actingAs($user)->test(Report::class, ['subjectType' => 'conversation', 'subjectId' => 9999])->assertNotFound();
    }

    public function test_a_message_can_be_reported_by_the_other_side_but_not_by_a_stranger(): void
    {
        [$sender, $owner, , $line] = $this->line();
        $message = app(LineService::class)->send($owner, $line->fresh(), 'Pay me a deposit first');

        Livewire::actingAs($this->person())->test(Report::class, ['subjectType' => 'message', 'subjectId' => $message->id])->assertNotFound();

        Livewire::actingAs($sender)->test(Report::class, ['subjectType' => 'message', 'subjectId' => $message->id, 'startOpen' => true])
            ->assertSee('What is wrong with this message?')
            ->set('reason', 'spam')->call('submit')->assertHasNoErrors();

        $this->assertSame(PulseMessage::class, PulseReport::query()->first()->reportable_type);
    }

    public function test_a_review_can_be_reported_by_someone_on_the_line_only(): void
    {
        [$sender, $owner, , $line] = $this->line();
        app(OutcomeService::class)->propose($sender, $line, 'won');
        app(OutcomeService::class)->confirm($owner, $line->fresh());
        Livewire::actingAs($sender)->test(Thread::class, ['conversation' => $line->fresh()])->set('review.rating', 5)->call('submitReview');
        $review = PulseReview::query()->first();

        Livewire::actingAs($this->person())->test(Report::class, ['subjectType' => 'review', 'subjectId' => $review->id])->assertNotFound();

        Livewire::actingAs($owner)->test(Report::class, ['subjectType' => 'review', 'subjectId' => $review->id])
            ->call('toggle')->set('reason', 'misinformation')->call('submit')->assertHasNoErrors()->assertSet('sent', true);
    }

    public function test_a_person_and_a_page_can_be_reported_but_not_yourself_or_your_own_page(): void
    {
        [$sender, $owner, $page] = $this->line();

        Livewire::actingAs($sender)->test(Report::class, ['subjectType' => 'entity', 'subjectId' => $page->id])
            ->call('toggle')->set('reason', 'spam')->call('submit')->assertSet('sent', true);

        Livewire::actingAs($owner)->test(Report::class, ['subjectType' => 'entity', 'subjectId' => $page->id])
            ->call('toggle')->set('reason', 'spam')->call('submit')
            ->assertSet('sent', false)
            ->assertSee('You cannot report that.');
        $this->assertSame(1, PulseReport::query()->count());
    }

    public function test_reporting_too_fast_is_met_with_a_polite_answer(): void
    {
        config(['pulse.lines.rate_limits.report' => 1]);
        [$sender, $owner, , $line] = $this->line();
        $first = app(LineService::class)->send($owner, $line->fresh(), 'one');
        $second = app(LineService::class)->send($owner, $line->fresh(), 'two');

        Livewire::actingAs($sender)->test(Report::class, ['subjectType' => 'message', 'subjectId' => $first->id])
            ->call('toggle')->set('reason', 'spam')->call('submit');

        Livewire::actingAs($sender)->test(Report::class, ['subjectType' => 'message', 'subjectId' => $second->id])
            ->call('toggle')->set('reason', 'spam')->call('submit')
            ->assertSee('You are doing that too quickly. Try again in');
    }

    public function test_the_thread_refreshes_when_a_line_is_reported(): void
    {
        [$sender, $owner, , $line] = $this->line();
        $message = app(LineService::class)->send($owner, $line->fresh(), 'Pay me a deposit first');

        Livewire::actingAs($sender)->test(Thread::class, ['conversation' => $line])
            ->assertSee('Report')
            ->call('reportMessage', $message->id)
            ->assertSet('reportingMessage', $message->id)
            ->assertSee('What is wrong with this message?')
            ->dispatch('line-reported', type: 'message', id: $message->id)
            ->assertSet('reportingMessage', null);
    }
}
