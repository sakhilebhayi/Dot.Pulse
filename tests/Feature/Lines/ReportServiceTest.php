<?php

namespace Tests\Feature\Lines;

use App\Models\PulseConversation;
use App\Models\PulseMessage;
use App\Models\PulseReport;
use App\Models\User;
use App\Notifications\ReportResolved;
use App\Services\Pulse\Lines\KnockBudget;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\ReportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private function reports(): ReportService
    {
        return app(ReportService::class);
    }

    private function moderator(): User
    {
        $m = $this->person();
        DB::table('pulse_profiles')->where('user_id', $m->id)->update(['role' => 'moderator']);

        return $m->fresh();
    }

    public function test_a_recipient_reports_a_request_as_spam_and_it_is_closed_for_them(): void
    {
        $spammer = $this->person();
        $target = $this->policy($this->person(), 'requests');
        $c = app(LineService::class)->knock($spammer, ['user' => $target->id], 'intro', $this->card());

        $report = $this->reports()->report($target, $c, 'spam', 'Selling me things');

        $this->assertSame('open', $report->status);
        $this->assertSame(PulseConversation::class, $report->reportable_type);
        $this->assertSame('spam', $c->fresh()->status);
        $this->assertSame('closed', app(LineService::class)->present($spammer, $c->fresh())['status'], 'the sender is never told it was marked as spam');
        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'report.create', 'actor_id' => $target->id]);
    }

    public function test_one_report_per_person_per_thing(): void
    {
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $c = $this->openLine($this->person(), $page);
        $owner = $this->responderOf($page);

        $first = $this->reports()->report($owner, $c, 'harassment', null);
        $second = $this->reports()->report($owner, $c->fresh(), 'harassment', null);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PulseReport::count());
    }

    public function test_who_can_report_what(): void
    {
        $customer = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);
        $c = $this->openLine($customer, $page);
        app(LineService::class)->send($customer, $c, 'a message');
        $message = PulseMessage::where('user_id', $customer->id)->where('kind', 'text')->latest('id')->first();
        $stranger = $this->person();

        // A stranger cannot report a line or message they cannot see.
        foreach ([$c, $message] as $subject) {
            try {
                $this->reports()->report($stranger, $subject, 'spam', null);
                $this->fail('not a participant');
            } catch (AuthorizationException) {
            }
        }

        // You cannot report your own message, or yourself, or your own page.
        foreach ([[$customer, $message], [$customer, $customer], [$owner, $page]] as [$by, $subject]) {
            try {
                $this->reports()->report($by, $subject, 'spam', null);
                $this->fail('own thing');
            } catch (AuthorizationException) {
            }
        }

        // A participant can report the other side's message, a person, a page, a listing.
        $this->assertInstanceOf(PulseReport::class, $this->reports()->report($owner, $message, 'harassment', null));
        $this->assertInstanceOf(PulseReport::class, $this->reports()->report($customer, $page, 'spam', null));
        $this->assertInstanceOf(PulseReport::class, $this->reports()->report($stranger, $customer, 'spam', null));
        $this->assertInstanceOf(PulseReport::class, $this->reports()->report($stranger, $this->offer($page), 'misinformation', null));
    }

    public function test_reason_and_note_are_validated(): void
    {
        $page = $this->page();

        try {
            $this->reports()->report($this->person(), $page, 'dislike', null);
            $this->fail('bad reason');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        try {
            $this->reports()->report($this->person(), $page, 'other', null);
            $this->fail('other needs a note');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('note', $e->errors());
        }

        $this->assertInstanceOf(PulseReport::class, $this->reports()->report($this->person(), $page, 'other', 'Pretending to be someone else'));
    }

    public function test_confirmed_spam_shrinks_the_senders_knock_budget_and_the_reporter_hears_once(): void
    {
        $spammer = $this->person();
        $target = $this->policy($this->person(), 'requests');
        $c = app(LineService::class)->knock($spammer, ['user' => $target->id], 'intro', $this->card());
        $report = $this->reports()->report($target, $c, 'spam', null);
        $before = app(KnockBudget::class)->remaining($spammer);

        $this->reports()->resolve($this->moderator(), $report, true, 'confirmed spam');

        $this->assertSame($before - 2, app(KnockBudget::class)->remaining($spammer));
        $this->assertSame('resolved', $report->fresh()->status);
        $this->assertSame(1, $target->notifications()->where('type', ReportResolved::class)->count());
        $this->assertDatabaseHas('pulse_moderation_logs', ['target_type' => PulseConversation::class, 'target_id' => $c->id, 'action' => 'review_report']);
        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'knock.shrink']);

        // Resolving twice changes nothing.
        $this->reports()->resolve($this->moderator(), $report->fresh(), true);
        $this->assertSame($before - 2, app(KnockBudget::class)->remaining($spammer));
    }

    public function test_a_dismissed_report_or_a_non_spam_reason_does_not_shrink_anything(): void
    {
        $sender = $this->person();
        $target = $this->policy($this->person(), 'requests');
        $other = $this->policy($this->person(), 'requests');
        $c1 = app(LineService::class)->knock($sender, ['user' => $target->id], 'intro', $this->card());
        $c2 = app(LineService::class)->knock($sender, ['user' => $other->id], 'intro', $this->card());
        $dismissed = $this->reports()->report($target, $c1, 'spam', null);
        $harassment = $this->reports()->report($other, $c2, 'harassment', null);
        $before = app(KnockBudget::class)->remaining($sender);

        $this->reports()->resolve($this->moderator(), $dismissed, false);
        $this->reports()->resolve($this->moderator(), $harassment, true);

        $this->assertSame($before, app(KnockBudget::class)->remaining($sender));
        $this->assertSame('dismissed', $dismissed->fresh()->status);
    }

    public function test_only_platform_moderators_resolve_reports(): void
    {
        $sender = $this->person();
        $target = $this->policy($this->person(), 'requests');
        $c = app(LineService::class)->knock($sender, ['user' => $target->id], 'intro', $this->card());
        $report = $this->reports()->report($target, $c, 'spam', null);

        $this->expectException(AuthorizationException::class);
        $this->reports()->resolve($target, $report, true);
    }

    public function test_a_moderator_can_read_a_reported_line_but_not_an_unreported_one(): void
    {
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $c = $this->openLine($this->person(), $page);
        $other = $this->openLine($this->person(), $page);
        $mod = $this->moderator();

        $this->assertFalse($mod->can('view', $c));
        $this->reports()->report($this->responderOf($page), $c, 'harassment', null);
        $this->assertTrue($mod->fresh()->can('view', $c));
        $this->assertFalse($mod->fresh()->can('view', $other));
    }

    public function test_the_message_policy(): void
    {
        $customer = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $c = $this->openLine($customer, $page);
        $message = PulseMessage::where('pulse_conversation_id', $c->id)->where('user_id', $customer->id)->where('kind', 'text')->first();
        $system = PulseMessage::where('pulse_conversation_id', $c->id)->where('kind', 'system')->first();
        $owner = $this->responderOf($page);

        $this->assertTrue($owner->can('view', $message));
        $this->assertFalse($this->person()->can('view', $message));
        $this->assertTrue($owner->can('report', $message));
        $this->assertFalse($customer->can('report', $message), 'not your own');
        $this->assertFalse($customer->can('report', $system), 'not system notes');
        $this->assertFalse($this->person()->can('report', $message));
    }

    public function test_report_rate_limit(): void
    {
        $by = $this->person();
        config(['pulse.lines.rate_limits.report' => 2]);

        $this->reports()->report($by, $this->page(), 'spam', null);
        $this->reports()->report($by, $this->page(), 'spam', null);

        $this->expectException(ThrottleRequestsException::class);
        $this->reports()->report($by, $this->page(), 'spam', null);
    }
}
