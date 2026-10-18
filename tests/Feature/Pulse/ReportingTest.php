<?php

namespace Tests\Feature\Pulse;

use App\Livewire\Pulse\ModerationQueue;
use App\Livewire\Pulse\ReportButton;
use App\Models\PulseComment;
use App\Models\PulsePost;
use App\Models\PulseReport;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ContentRemoved;
use App\Notifications\ReportResolved;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

class ReportingTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    private function report(User $reporter, $target, string $reason = 'spam', ?string $note = null): void
    {
        $type = match (true) {
            $target instanceof PulsePost => 'post',
            $target instanceof PulseComment => 'comment',
            default => 'user',
        };

        Livewire::actingAs($reporter)->test(ReportButton::class, ['targetType' => $type, 'targetId' => $target->id])
            ->set('reason', $reason)
            ->set('note', $note ?? '')
            ->call('submit');
    }

    public function test_a_user_can_report_a_post_a_comment_and_a_profile(): void
    {
        $reporter = $this->pulseUser();
        $post = $this->postIn(null);
        $comment = $this->commentOn($post);
        $profile = $this->pulseUser();

        $this->report($reporter, $post, 'spam');
        $this->report($reporter, $comment, 'harassment', 'Targets me every day.');
        $this->report($reporter, $profile, 'other', 'Impersonating a colleague.');

        $this->assertSame(3, PulseReport::count());
        $this->assertDatabaseHas('pulse_reports', ['reporter_id' => $reporter->id, 'reportable_type' => PulsePost::class, 'reportable_id' => $post->id, 'reason' => 'spam', 'status' => 'open']);
        $this->assertDatabaseHas('pulse_reports', ['reportable_type' => PulseComment::class, 'reportable_id' => $comment->id, 'details' => 'Targets me every day.']);
        $this->assertDatabaseHas('pulse_reports', ['reportable_type' => User::class, 'reportable_id' => $profile->id, 'community_id' => null]);
    }

    public function test_a_report_remembers_the_community_of_what_was_reported(): void
    {
        $community = $this->privateCommunity();
        $member = $this->joined($community);
        $post = $this->postIn($community);
        $comment = $this->commentOn($post);

        $this->report($member, $post);
        $this->report($member, $comment, 'hate');

        $this->assertSame(2, PulseReport::where('community_id', $community->id)->count());
    }

    public function test_the_reason_must_come_from_the_list_and_other_needs_a_note(): void
    {
        $reporter = $this->pulseUser();
        $post = $this->postIn(null);

        Livewire::actingAs($reporter)->test(ReportButton::class, ['targetType' => 'post', 'targetId' => $post->id])
            ->set('reason', 'because-i-said-so')
            ->call('submit')
            ->assertHasErrors('reason');

        Livewire::actingAs($reporter)->test(ReportButton::class, ['targetType' => 'post', 'targetId' => $post->id])
            ->set('reason', 'other')
            ->set('note', '')
            ->call('submit')
            ->assertHasErrors('note');

        Livewire::actingAs($reporter)->test(ReportButton::class, ['targetType' => 'post', 'targetId' => $post->id])
            ->set('reason', 'spam')
            ->set('note', str_repeat('x', 501))
            ->call('submit')
            ->assertHasErrors('note');

        $this->assertSame(0, PulseReport::count());
    }

    public function test_one_report_per_person_per_target(): void
    {
        $reporter = $this->pulseUser();
        $post = $this->postIn(null);

        $this->report($reporter, $post);
        $this->report($reporter, $post, 'hate');

        $this->assertSame(1, PulseReport::count());
        $this->assertSame('spam', PulseReport::first()->reason);

        // And the database refuses a duplicate even if the app were bypassed.
        $this->expectException(UniqueConstraintViolationException::class);
        PulseReport::create([
            'reporter_id' => $reporter->id,
            'reportable_type' => PulsePost::class,
            'reportable_id' => $post->id,
            'reason' => 'spam',
        ]);
    }

    public function test_the_report_button_shows_reported_once_done(): void
    {
        $reporter = $this->pulseUser();
        $post = $this->postIn(null);
        $this->report($reporter, $post);

        Livewire::actingAs($reporter)->test(ReportButton::class, ['targetType' => 'post', 'targetId' => $post->id])
            ->assertSee('Reported');
    }

    public function test_you_cannot_report_your_own_post_comment_or_profile(): void
    {
        $me = $this->pulseUser();
        $post = $this->postIn(null, ['user_id' => $me->id]);
        $comment = $this->commentOn($post, ['user_id' => $me->id]);

        foreach ([['post', $post->id], ['comment', $comment->id], ['user', $me->id]] as [$type, $id]) {
            Livewire::actingAs($me)->test(ReportButton::class, ['targetType' => $type, 'targetId' => $id])
                ->assertForbidden();
        }
    }

    public function test_you_cannot_report_what_you_cannot_see(): void
    {
        $community = $this->privateCommunity();
        $post = $this->postIn($community);
        $held = $this->postIn(null, ['status' => 'flagged']);

        Livewire::actingAs($this->pulseUser())->test(ReportButton::class, ['targetType' => 'post', 'targetId' => $post->id])
            ->assertNotFound();
        Livewire::actingAs($this->pulseUser())->test(ReportButton::class, ['targetType' => 'post', 'targetId' => $held->id])
            ->assertNotFound();
        $this->assertSame(0, PulseReport::count());
    }

    public function test_reports_feed_the_queue_grouped_by_target_with_counts(): void
    {
        $post = $this->postIn(null, ['body' => 'A post that three people reported today.']);
        $other = $this->postIn(null, ['body' => 'A post that only one person reported.']);
        foreach (['spam', 'spam', 'harassment'] as $reason) {
            $this->report($this->pulseUser(), $post, $reason);
        }
        $this->report($this->pulseUser(), $other, 'hate');

        $component = Livewire::actingAs($this->platformModerator())->test(ModerationQueue::class)
            ->set('tab', 'reports')
            ->assertSee('A post that three people reported today.')
            ->assertSee('3 reports')
            ->assertSee('1 report');

        $groups = $component->instance()->reportGroups();
        $this->assertCount(2, $groups);
        $this->assertSame(3, $groups->first()['count']);
        $this->assertSame(['harassment' => 1, 'spam' => 2], collect($groups->first()['reasons'])->sortKeys()->all());
    }

    public function test_the_queue_never_shows_who_reported(): void
    {
        $reporter = $this->pulseUser();
        $reporter->forceFill(['name' => 'Reporter Whose Name Must Stay Hidden'])->save();
        $post = $this->postIn(null);
        $this->report($reporter, $post, 'other', 'This note is visible to moderators only.');

        Livewire::actingAs($this->platformModerator())->test(ModerationQueue::class)
            ->set('tab', 'reports')
            ->assertSee('This note is visible to moderators only.')
            ->assertDontSee('Reporter Whose Name Must Stay Hidden');
    }

    public function test_a_community_moderator_sees_only_reports_from_their_community(): void
    {
        $mine = $this->privateCommunity();
        $moderator = $this->joined($mine, null, 'moderator');
        $member = $this->joined($mine);
        $inside = $this->postIn($mine, ['body' => 'A reported post inside my community room.']);
        $outside = $this->postIn(null, ['body' => 'A reported post with no community at all.']);
        $this->report($member, $inside);
        $this->report($this->pulseUser(), $outside);
        $this->report($this->pulseUser(), $this->pulseUser());

        Livewire::actingAs($moderator)->test(ModerationQueue::class)
            ->set('tab', 'reports')
            ->assertSee('A reported post inside my community room.')
            ->assertDontSee('A reported post with no community at all.');

        // Profile reports are platform business.
        $groups = Livewire::actingAs($moderator)->test(ModerationQueue::class)->set('tab', 'reports')->instance()->reportGroups();
        $this->assertCount(1, $groups);
    }

    public function test_resolving_a_report_removes_the_post_logs_it_and_notifies_both_sides_minimally(): void
    {
        Notification::fake();
        $post = $this->postIn(null, ['body' => 'A post that the moderator will take down.']);
        $reporter = $this->pulseUser();
        $this->report($reporter, $post);
        $moderator = $this->platformModerator();

        Livewire::actingAs($moderator)->test(ModerationQueue::class)
            ->set('tab', 'reports')
            ->call('promptResolve', PulsePost::class, $post->id)
            ->set('resolveReason', 'Confirmed spam.')
            ->call('resolveReports', PulsePost::class, $post->id);

        $this->assertSame('removed', $post->fresh()->status);
        $this->assertDatabaseHas('pulse_reports', ['reportable_id' => $post->id, 'status' => 'resolved', 'resolved_by' => $moderator->id, 'resolution' => 'removed']);
        $this->assertDatabaseHas('pulse_moderation_logs', ['target_type' => PulsePost::class, 'target_id' => $post->id, 'action' => 'remove', 'rationale' => 'Confirmed spam.', 'moderator_id' => $moderator->id]);

        Notification::assertSentTo($reporter, ReportResolved::class, function ($n) use ($reporter, $moderator) {
            $json = json_encode($n->toDatabase($reporter));

            return $n->actioned === true && ! str_contains($json, $moderator->name);
        });
        Notification::assertSentTo($post->author, ContentRemoved::class, function ($n) use ($reporter, $post) {
            $json = json_encode($n->toDatabase($post->author));

            return ! str_contains($json, $reporter->name) && ! str_contains($json, (string) $reporter->id.'"');
        });
    }

    public function test_resolving_a_comment_report_removes_the_comment(): void
    {
        $post = $this->postIn(null);
        $comment = $this->commentOn($post);
        PulsePost::syncCommentsCount($post->id);
        $this->report($this->pulseUser(), $comment, 'harassment');

        Livewire::actingAs($this->platformModerator())->test(ModerationQueue::class)
            ->set('tab', 'reports')
            ->call('promptResolve', PulseComment::class, $comment->id)
            ->set('resolveReason', 'Abusive.')
            ->call('resolveReports', PulseComment::class, $comment->id);

        $this->assertSoftDeleted('pulse_comments', ['id' => $comment->id]);
        $this->assertSame(0, $post->fresh()->comments_count);
        $this->assertDatabaseHas('pulse_moderation_logs', ['target_type' => PulseComment::class, 'target_id' => $comment->id, 'action' => 'remove']);
    }

    public function test_dismissing_keeps_the_content_logs_it_and_tells_only_the_reporter(): void
    {
        Notification::fake();
        $post = $this->postIn(null);
        $reporter = $this->pulseUser();
        $this->report($reporter, $post);
        $moderator = $this->platformModerator();

        Livewire::actingAs($moderator)->test(ModerationQueue::class)
            ->set('tab', 'reports')
            ->call('dismissReports', PulsePost::class, $post->id);

        $this->assertSame('published', $post->fresh()->status);
        $this->assertDatabaseHas('pulse_reports', ['reportable_id' => $post->id, 'status' => 'dismissed', 'resolution' => 'dismissed']);
        $this->assertDatabaseHas('pulse_moderation_logs', ['target_id' => $post->id, 'action' => 'dismiss_report', 'moderator_id' => $moderator->id]);
        Notification::assertSentTo($reporter, ReportResolved::class, fn ($n) => $n->actioned === false);
        Notification::assertNotSentTo($post->author, ContentRemoved::class);
    }

    public function test_a_profile_report_can_be_marked_reviewed_by_a_platform_moderator(): void
    {
        $profile = $this->pulseUser();
        $this->report($this->pulseUser(), $profile, 'other', 'Looks like a fake account.');

        Livewire::actingAs($this->platformModerator())->test(ModerationQueue::class)
            ->set('tab', 'reports')
            ->call('resolveReports', User::class, $profile->id);

        $this->assertDatabaseHas('pulse_reports', ['reportable_id' => $profile->id, 'status' => 'resolved', 'resolution' => 'reviewed']);
        $this->assertDatabaseHas('pulse_moderation_logs', ['target_type' => User::class, 'target_id' => $profile->id, 'action' => 'review_report']);
    }

    public function test_a_community_moderator_cannot_resolve_reports_outside_their_community(): void
    {
        $mine = $this->privateCommunity();
        $moderator = $this->joined($mine, null, 'moderator');
        $post = $this->postIn(null);
        $this->report($this->pulseUser(), $post);

        Livewire::actingAs($moderator)->test(ModerationQueue::class)
            ->call('dismissReports', PulsePost::class, $post->id)
            ->assertForbidden();

        $this->assertSame('open', PulseReport::first()->status);
    }

    public function test_resolve_only_accepts_reportable_classes(): void
    {
        Livewire::actingAs($this->platformModerator())->test(ModerationQueue::class)
            ->call('dismissReports', Team::class, 1)
            ->assertStatus(404);
    }
}
