<?php

namespace Tests\Feature\Lines;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseMessage;
use App\Models\PulseReport;
use App\Models\PulseSignal;
use App\Models\User;
use App\Notifications\PulseNotice;
use App\Services\Pulse\Lines\ConsentLedger;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\OutcomeService;
use App\Services\Pulse\Lines\PrivacyExport;
use App\Services\Pulse\Lines\ReportService;
use App\Services\Pulse\Lines\ReviewService;
use App\Services\Pulse\Lines\SweepLines;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

/**
 * An employer page invites an anonymous professional by signal. Until the professional reveals themselves, nothing
 * the page can read, in any shape, may carry who they are.
 */
class AnonymousInviteTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private User $recruiter;

    private User $teammate;

    private PulseEntity $employer;

    private User $pro;

    private PulseSignal $signal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recruiter = $this->person();
        $this->employer = $this->page($this->recruiter, ['name' => 'Karoo Logistics', 'is_employer' => true, 'contact_policy' => 'requests'], 'owner');
        $this->teammate = $this->member($this->employer, null, 'responder');
        [$this->pro, $this->signal] = $this->talent('anonymous');
    }

    private function lines(): LineService
    {
        return app(LineService::class);
    }

    private function invite(): PulseConversation
    {
        return $this->lines()->knock(
            $this->recruiter,
            ['signal' => $this->signal->id, 'as_entity' => $this->employer->id],
            'invite',
            $this->card(['what' => 'We would like to talk about a bookkeeping role']),
        );
    }

    /** The strings and keys that identify the professional. */
    private function assertNoIdentity(mixed $data, string $where): void
    {
        $json = json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR);

        foreach (['Thandiwe', 'Zulu-Private', 'thandi.private', $this->pro->email, $this->pro->name] as $needle) {
            $this->assertStringNotContainsString($needle, $json, "{$where} leaks {$needle}");
        }

        foreach (['recipient_id', 'owner_id', 'user_id'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', $json, "{$where} carries {$key}");
        }

        $this->assertStringNotContainsString('profile_photo', $json, "{$where} carries an avatar");
    }

    public function test_the_invite_is_hidden_requested_and_addressed_by_signal_not_by_person(): void
    {
        $c = $this->invite();

        $this->assertSame('invite', $c->kind);
        $this->assertSame('requested', $c->status);
        $this->assertTrue($c->identity_hidden);
        $this->assertSame($this->employer->id, $c->initiator_entity_id);
        $this->assertSame($this->pro->id, $c->getAttribute('recipient_id'));
        $this->assertArrayNotHasKey('recipient_id', $c->toArray(), 'the model never serialises the invited person');
        $this->assertArrayNotHasKey('context_id', $c->toArray(), 'nor the signal that names its owner');
        $this->assertStringNotContainsString('recipient_id', $c->toJson());
    }

    public function test_the_page_sees_only_the_anonymous_card_in_every_read_path(): void
    {
        $c = $this->invite();
        $this->lines()->accept($this->pro, $c);
        $this->lines()->send($this->pro, $c->fresh(), 'Happy to talk. I am a bookkeeper.');
        $this->lines()->send($this->recruiter, $c->fresh(), 'Great, tell us about payroll.');
        $this->lines()->markRead($this->recruiter, $c->fresh());

        DB::enableQueryLog();

        foreach ([$this->recruiter, $this->teammate] as $viewer) {
            $present = $this->lines()->present($viewer, $c->fresh());
            $thread = $this->lines()->thread($viewer, $c->fresh());
            $inbox = $this->lines()->inbox($viewer, ['tab' => 'open'])->items();
            $export = PrivacyExport::forUser($viewer);
            $ledger = app(ConsentLedger::class)->entries($viewer);

            $this->assertNoIdentity($present, 'present()');
            $this->assertNoIdentity($thread, 'thread()');
            $this->assertNoIdentity($inbox, 'inbox()');
            $this->assertNoIdentity($export['lines'], 'privacy export');
            $this->assertNoIdentity($ledger, 'consent ledger');

            $this->assertSame('anonymous', $present['counterpart']['kind']);
            $this->assertTrue($present['counterpart']['hidden']);
            $this->assertSame('Anonymous professional', $present['counterpart']['name']);
            $this->assertStringStartsWith('Professional ', $present['counterpart']['alias']);
            $this->assertNull($present['context']['id'], 'the page cannot walk from the line to the signal and its owner');
            $this->assertSame('Bookkeeper, 7 years, payroll and VAT', $present['counterpart']['card']['headline']);
            $this->assertSame(['bookkeeping', 'payroll', 'vat'], $present['counterpart']['card']['skills']);
            $this->assertSame('6-10 years', $present['counterpart']['card']['experience_band']);
            $this->assertSame('Gauteng', $present['counterpart']['card']['region']);
            $this->assertTrue($present['identity_hidden']);
        }

        // The messages from the professional are labelled with the alias, never with a name.
        $theirs = collect($this->lines()->thread($this->recruiter, $c->fresh()))->firstWhere('body', 'Happy to talk. I am a bookkeeper.');
        $this->assertStringStartsWith('Professional ', $theirs['from']);

        // No query run on behalf of the page loaded the professional's user row.
        $leaked = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "users"') && in_array($this->pro->id, $q['bindings'], true));
        $this->assertCount(0, $leaked, 'a users query for the professional ran for the page: '.$leaked->pluck('query')->implode(' | '));
    }

    public function test_the_alias_is_stable_per_line_and_different_across_lines(): void
    {
        $first = $this->invite();
        $this->lines()->close($this->recruiter, $first);

        [$sameProSecondEmployer] = [$this->page(null, ['is_employer' => true, 'contact_policy' => 'requests'])];
        $second = $this->lines()->knock($this->responderOf($sameProSecondEmployer), ['signal' => $this->signal->id, 'as_entity' => $sameProSecondEmployer->id], 'invite', $this->card());

        $a = $this->lines()->present($this->recruiter, $first)['counterpart']['alias'];
        $a2 = $this->lines()->present($this->teammate, $first)['counterpart']['alias'];
        $b = $this->lines()->present($this->responderOf($sameProSecondEmployer), $second)['counterpart']['alias'];

        $this->assertSame($a, $a2);
        $this->assertNotSame($a, $b, 'two pages cannot tell they invited the same person');
    }

    public function test_no_notification_to_the_page_carries_the_professional_even_through_the_whole_life_of_the_line(): void
    {
        $c = $this->invite();
        $this->lines()->accept($this->pro, $c);
        $this->lines()->send($this->pro, $c->fresh(), 'Hello from the professional');
        $this->lines()->send($this->pro, $c->fresh(), 'And a second message');
        app(OutcomeService::class)->propose($this->pro, $c->fresh(), 'hired');
        app(OutcomeService::class)->dispute($this->recruiter, $c->fresh());
        app(OutcomeService::class)->propose($this->recruiter, $c->fresh(), 'hired');
        app(OutcomeService::class)->confirm($this->pro, $c->fresh());

        foreach ([$this->recruiter, $this->teammate] as $user) {
            $rows = $user->notifications()->get();
            $this->assertGreaterThan(0, $rows->count());
            foreach ($rows as $row) {
                $this->assertNoIdentity($row->data, 'notification '.$row->data['type']);
                $this->assertNoIdentity((new PulseNotice($row->data, ['mail']))->toMail($user)->toArray(), 'mail '.$row->data['type']);
            }
        }

        $titles = $this->recruiter->notifications()->get()->pluck('data.title')->implode(' | ');
        $this->assertStringContainsString('An anonymous professional', $titles);
    }

    public function test_a_declined_invite_and_an_expired_one_also_leak_nothing(): void
    {
        $declined = $this->invite();
        $this->lines()->decline($this->pro, $declined, 'not_a_fit');

        [$otherPro, $otherSignal] = $this->talent('anonymous', ['name' => 'Sipho Nkosi-Private']);
        $other = $this->lines()->knock($this->recruiter, ['signal' => $otherSignal->id, 'as_entity' => $this->employer->id], 'invite', $this->card());
        $this->travel(15)->days();
        app(SweepLines::class)->expireRequests();

        foreach ($this->recruiter->notifications()->get() as $row) {
            $this->assertNoIdentity($row->data, 'notification '.$row->data['type']);
            $this->assertStringNotContainsString('Nkosi', json_encode($row->data));
        }

        $this->assertSame('closed', $other->fresh()->status);
    }

    public function test_the_page_cannot_reveal_and_neither_can_a_stranger_but_the_professional_can(): void
    {
        $c = $this->invite();
        $this->lines()->accept($this->pro, $c);

        foreach ([$this->recruiter, $this->teammate, $this->person()] as $nope) {
            try {
                $this->lines()->reveal($nope, $c->fresh());
                $this->fail('only the invited professional can reveal');
            } catch (AuthorizationException) {
            }
        }

        $this->assertTrue($c->fresh()->identity_hidden);

        $this->lines()->reveal($this->pro, $c->fresh());

        $this->assertFalse($c->fresh()->identity_hidden);
        $present = $this->lines()->present($this->recruiter, $c->fresh());
        $this->assertSame('person', $present['counterpart']['kind']);
        $this->assertSame($this->pro->name, $present['counterpart']['name']);
        $this->assertNotNull($present['context']['id']);

        $note = $this->recruiter->notifications()->where('data', 'like', '%line_revealed%')->first();
        $this->assertStringContainsString($this->pro->name, $note->data['title']);
        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'line.reveal', 'actor_id' => $this->pro->id]);
    }

    public function test_revealing_needs_an_open_line_and_a_hidden_identity(): void
    {
        $c = $this->invite();

        try {
            $this->lines()->reveal($this->pro, $c);
            $this->fail('not open yet');
        } catch (AuthorizationException) {
        }

        $this->lines()->accept($this->pro, $c);
        $this->lines()->reveal($this->pro, $c->fresh());
        $this->lines()->reveal($this->pro, $c->fresh()); // idempotent: nothing to do, no error
        $this->assertSame(1, PulseMessage::where('pulse_conversation_id', $c->id)->where('meta', 'like', '%revealed%')->count());
    }

    public function test_the_professional_sees_the_page_not_the_staff_member_who_sent_it(): void
    {
        $c = $this->invite();
        $present = $this->lines()->present($this->pro, $c);

        $this->assertSame('entity', $present['counterpart']['kind']);
        $this->assertSame('Karoo Logistics', $present['counterpart']['name']);
        $this->assertFalse($present['identity_hidden']);
        $this->assertStringNotContainsString($this->recruiter->name, json_encode($present));

        $note = $this->pro->notifications()->first();
        $this->assertStringContainsString('Karoo Logistics', $note->data['title']);
        $this->assertStringNotContainsString($this->recruiter->name, json_encode($note->data));
    }

    public function test_who_can_invite_and_who_can_be_invited(): void
    {
        $lines = $this->lines();

        // Someone who does not answer for the page.
        try {
            $lines->knock($this->person(), ['signal' => $this->signal->id, 'as_entity' => $this->employer->id], 'invite', $this->card());
            $this->fail('not a member');
        } catch (AuthorizationException) {
        }

        // An editor of the page cannot speak for it.
        $editor = $this->member($this->employer, null, 'editor');
        try {
            $lines->knock($editor, ['signal' => $this->signal->id, 'as_entity' => $this->employer->id], 'invite', $this->card());
            $this->fail('editors do not answer lines');
        } catch (AuthorizationException) {
        }

        // A page that is not an employer.
        $seller = $this->page(null, ['is_employer' => false]);
        try {
            $lines->knock($this->responderOf($seller), ['signal' => $this->signal->id, 'as_entity' => $seller->id], 'invite', $this->card());
            $this->fail('not an employer');
        } catch (LineException $e) {
            $this->assertSame('not_allowed', $e->reason);
        }

        // An invite without a page.
        try {
            $lines->knock($this->recruiter, ['signal' => $this->signal->id], 'invite', $this->card());
            $this->fail('invites come from a page');
        } catch (LineException) {
        }

        // A professional who switched discoverability off, or turned contact off, or lost their signal.
        DB::table('pulse_profiles')->where('user_id', $this->pro->id)->update(['discoverability' => 'off']);
        try {
            $lines->knock($this->recruiter, ['signal' => $this->signal->id, 'as_entity' => $this->employer->id], 'invite', $this->card());
            $this->fail('not discoverable');
        } catch (LineException $e) {
            $this->assertSame('unavailable', $e->reason);
        }

        DB::table('pulse_profiles')->where('user_id', $this->pro->id)->update(['discoverability' => 'anonymous', 'contact_policy' => 'nobody']);
        try {
            $lines->knock($this->recruiter, ['signal' => $this->signal->id, 'as_entity' => $this->employer->id], 'invite', $this->card());
            $this->fail('nobody');
        } catch (LineException $e) {
            $this->assertSame('nobody', $e->getMessage() === '' ? '' : 'nobody');
        }

        DB::table('pulse_profiles')->where('user_id', $this->pro->id)->update(['contact_policy' => 'context']);
        $this->signal->update(['status' => 'paused']);
        $this->expectException(LineException::class);
        $lines->knock($this->recruiter, ['signal' => $this->signal->id, 'as_entity' => $this->employer->id], 'invite', $this->card());
    }

    public function test_a_minor_cannot_be_invited_and_an_unconfirmed_recruiter_cannot_invite(): void
    {
        DB::table('pulse_profiles')->where('user_id', $this->pro->id)->update(['age_confirmed_at' => null]);

        try {
            $this->invite();
            $this->fail('minor');
        } catch (LineException $e) {
            $this->assertSame('not_allowed', $e->reason);
        }

        DB::table('pulse_profiles')->where('user_id', $this->pro->id)->update(['age_confirmed_at' => now()]);
        DB::table('pulse_profiles')->where('user_id', $this->recruiter->id)->update(['age_confirmed_at' => null]);

        $this->expectException(LineException::class);
        $this->invite();
    }

    public function test_an_open_professional_is_invited_by_name_and_not_hidden(): void
    {
        [$openPro, $openSignal] = $this->talent('open');

        $c = $this->lines()->knock($this->recruiter, ['signal' => $openSignal->id, 'as_entity' => $this->employer->id], 'invite', $this->card());

        $this->assertFalse($c->identity_hidden);
        $this->assertSame($openPro->name, $this->lines()->present($this->recruiter, $c)['counterpart']['name']);
    }

    public function test_inviting_by_user_id_still_hides_an_anonymous_professional(): void
    {
        $c = $this->lines()->knock($this->recruiter, ['user' => $this->pro->id, 'as_entity' => $this->employer->id], 'invite', $this->card());

        $this->assertTrue($c->identity_hidden);
        $this->assertNoIdentity($this->lines()->present($this->recruiter, $c), 'present()');
    }

    public function test_a_hired_outcome_still_does_not_let_the_page_review_a_hidden_professional(): void
    {
        $c = $this->invite();
        $this->lines()->accept($this->pro, $c);
        $outcomes = app(OutcomeService::class);
        $outcomes->propose($this->recruiter, $c->fresh(), 'hired');
        $outcomes->confirm($this->pro, $c->fresh());

        $reviews = app(ReviewService::class);
        $this->assertNull($reviews->canReview($this->recruiter, $this->pro));

        try {
            $reviews->submit($this->recruiter, $c->fresh(), ['rating' => 5]);
            $this->fail('cannot review a person who is hidden');
        } catch (LineException $e) {
            $this->assertStringContainsString('reveal', $e->getMessage());
        }

        // The professional may review the employer.
        $review = $reviews->submit($this->pro, $c->fresh(), ['rating' => 4, 'body' => 'Clear and respectful.', 'pseudonymous' => true]);
        $this->assertSame($this->employer->id, $review->reviewable_id);
    }

    public function test_a_hidden_professional_review_is_forced_pseudonymous_until_they_reveal_themselves(): void
    {
        $c = $this->invite();
        $this->lines()->accept($this->pro, $c);
        $outcomes = app(OutcomeService::class);
        $outcomes->propose($this->recruiter, $c->fresh(), 'hired');
        $outcomes->confirm($this->pro, $c->fresh());

        $review = app(ReviewService::class)->submit($this->pro, $c->fresh(), ['rating' => 5, 'body' => 'Fair and quick.', 'pseudonymous' => false]);

        $this->assertTrue($review->pseudonymous, 'the form said named, the service says no');
        $presented = app(ReviewService::class)->present($review->load('author'));
        $this->assertStringNotContainsString(explode(' ', $this->pro->name)[0], $presented['author']);
        $this->assertSame('A person who was hired', $presented['author']);
    }

    public function test_reporting_an_anonymous_line_works_for_the_page_without_learning_who(): void
    {
        $c = $this->invite();
        $this->lines()->accept($this->pro, $c);
        $this->lines()->send($this->pro, $c->fresh(), 'something rude');

        $report = app(ReportService::class)->report($this->recruiter, $c->fresh(), 'harassment', 'rude');

        $this->assertInstanceOf(PulseReport::class, $report);
        $this->assertSame('closed', $c->fresh()->status);
        $this->assertNoIdentity(['status' => $report->status, 'reason' => $report->reason, 'details' => $report->details], 'report');
    }

    public function test_a_moderator_reviewing_a_report_sees_the_alias_not_the_name(): void
    {
        $c = $this->invite();
        $this->lines()->accept($this->pro, $c);
        $this->lines()->send($this->pro, $c->fresh(), 'hello');
        app(ReportService::class)->report($this->pro, $c->fresh(), 'spam', null);

        $moderator = $this->person();
        DB::table('pulse_profiles')->where('user_id', $moderator->id)->update(['role' => 'moderator']);
        $moderator = $moderator->fresh();

        $thread = $this->lines()->thread($moderator, $c->fresh());
        $this->assertNoIdentity($thread, 'moderator thread');
        $this->assertNoIdentity($this->lines()->present($moderator, $c->fresh()), 'moderator view');
    }

    public function test_a_talent_signal_can_only_be_approached_with_an_invitation_from_a_page(): void
    {
        $someone = $this->person();

        $this->expectException(LineException::class);
        $this->lines()->knock($someone, ['signal' => $this->signal->id], 'intro', $this->card());
    }
}
