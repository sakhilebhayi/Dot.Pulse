<?php

namespace Tests\Feature\Lines;

use App\Models\PulseAuditEntry;
use App\Models\PulseBlock;
use App\Models\PulseMessage;
use App\Models\PulseMute;
use App\Models\PulseNotificationPref;
use App\Models\PulseReview;
use App\Models\PulseSave;
use App\Models\PulseSignal;
use App\Services\Pulse\Lines\BlockService;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\MuteService;
use App\Services\Pulse\Lines\NotificationPrefs;
use App\Services\Pulse\Lines\OutcomeService;
use App\Services\Pulse\Lines\PrivacyEraser;
use App\Services\Pulse\Lines\PrivacyExport;
use App\Services\Pulse\Lines\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class PrivacyTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    public function test_the_export_holds_everything_about_the_person_and_is_json_serialisable(): void
    {
        $me = $this->policy($this->person(['headline' => 'Baker', 'bio' => 'I bake', 'experience_years' => 4], ['name' => 'Naledi Export']), 'requests');
        $other = $this->person([], ['name' => 'Karel Other', 'email' => 'karel.private@example.test']);
        $page = $this->page(null, ['contact_policy' => 'requests', 'name' => 'Moyo Plumbing']);
        $this->member($page, $me, 'responder');
        $need = $this->need($me, ['title' => 'Need flour in bulk']);
        app(MuteService::class)->mute($me, 'topic', 'crypto');
        app(BlockService::class)->block($me, $this->person());
        PulseSave::create(['user_id' => $me->id, 'saveable_type' => 'signal', 'saveable_id' => 1]);
        app(NotificationPrefs::class)->update($me, ['frequency' => 'daily']);

        $lines = app(LineService::class);
        $line = $lines->knock($other, ['user' => $me->id], 'intro', $this->card(['what' => 'Let us talk about flour']));
        $lines->accept($me, $line);
        $lines->send($me, $line->fresh(), 'My message to Karel');
        $lines->send($other, $line->fresh(), 'Karel says hello back');

        $export = PrivacyExport::forUser($me);
        $json = json_encode($export);

        $this->assertNotFalse($json);
        $this->assertSame('Naledi Export', $export['account']['name']);
        $this->assertSame('Baker', $export['profile']['headline']);
        $this->assertSame('requests', $export['profile']['contact_policy']);
        $this->assertSame('daily', $export['notification_preferences']['frequency']);
        $this->assertCount(1, $export['mutes']);
        $this->assertCount(1, $export['saves']);
        $this->assertSame(1, $export['blocks']['people_you_blocked']);
        $this->assertSame('Need flour in bulk', $export['needs'][0]['title']);
        $this->assertSame('Moyo Plumbing', $export['pages'][0]['page']);
        $this->assertNotEmpty($export['consent']);
        $this->assertNotEmpty($export['activity_log']);

        $this->assertCount(1, $export['lines']);
        $theLine = $export['lines'][0];
        $this->assertSame('Karel Other', $theLine['other_side']);
        $texts = collect($theLine['messages'])->pluck('text')->implode(' | ');
        $this->assertStringContainsString('My message to Karel', $texts);
        $this->assertStringContainsString('Karel says hello back', $texts);
        $this->assertSame('You', collect($theLine['messages'])->firstWhere('text', 'My message to Karel')['from']);

        // The other party's private data is not in my export.
        $this->assertStringNotContainsString('karel.private@example.test', $json);
        $this->assertStringNotContainsString('"email":"karel', $json);
    }

    public function test_the_export_of_an_anonymous_line_never_names_the_professional_to_the_page(): void
    {
        $recruiter = $this->person();
        $employer = $this->page($recruiter, ['is_employer' => true, 'contact_policy' => 'requests']);
        [$pro, $signal] = $this->talent('anonymous');
        $c = app(LineService::class)->knock($recruiter, ['signal' => $signal->id, 'as_entity' => $employer->id], 'invite', $this->card());
        app(LineService::class)->accept($pro, $c);
        app(LineService::class)->send($pro, $c->fresh(), 'Hello');

        $json = json_encode(PrivacyExport::forUser($recruiter));

        $this->assertStringNotContainsString('Thandiwe', $json);
        $this->assertStringNotContainsString($pro->email, $json);
        $this->assertStringContainsString('An anonymous professional', $json);
    }

    public function test_erase_anonymises_messages_removes_discoverability_and_keeps_the_audit_without_the_actor(): void
    {
        $me = $this->policy($this->person(['headline' => 'Baker', 'bio' => 'private bio', 'discoverability' => 'open', 'experience_years' => 4]), 'requests');
        [$talentMe, $signal] = [$me, PulseSignal::create([
            'kind' => 'talent', 'subject_type' => 'profile', 'subject_id' => 1, 'owner_id' => $me->id, 'title' => 'Baker', 'status' => 'live', 'published_at' => now(),
        ])];
        $other = $this->person();
        $need = $this->need($me);
        app(MuteService::class)->mute($me, 'topic', 'x');
        PulseSave::create(['user_id' => $me->id, 'saveable_type' => 'signal', 'saveable_id' => 1]);
        app(NotificationPrefs::class)->update($me, ['frequency' => 'daily']);
        app(BlockService::class)->block($me, $this->person());
        $blockedMe = $this->person();
        app(BlockService::class)->block($blockedMe, $me);

        $lines = app(LineService::class);
        $line = $lines->knock($other, ['user' => $me->id], 'intro', $this->card());
        $lines->accept($me, $line);
        $lines->send($me, $line->fresh(), 'something personal I said');
        $lines->send($other, $line->fresh(), 'what the other person said');
        $openByMe = $lines->knock($me, ['user' => $this->policy($this->person(), 'requests')->id], 'intro', $this->card());

        $result = PrivacyEraser::erase($me);

        $this->assertSame(3, $result['messages'], 'my message, the accept note and my own knock card');
        $mine = PulseMessage::where('user_id', $me->id)->get();
        $this->assertTrue($mine->every(fn ($m) => $m->erased_at !== null && $m->body === 'This message was deleted.'));
        $this->assertSame('what the other person said', PulseMessage::where('user_id', $other->id)->where('body', 'what the other person said')->value('body'), 'the other side keeps their words');

        $profile = DB::table('pulse_profiles')->where('user_id', $me->id)->first();
        $this->assertNull($profile->headline);
        $this->assertNull($profile->bio);
        $this->assertSame('off', $profile->discoverability);
        $this->assertSame('nobody', $profile->contact_policy);
        $this->assertSame('removed', $signal->fresh()->status);
        $this->assertSame('Removed', $signal->fresh()->title, 'the words are gone, not only the visibility');
        $this->assertSame('removed', $need->fresh()->status);
        $this->assertNull($need->fresh()->details);
        $this->assertSame('Removed', $need->fresh()->title);

        $this->assertSame(0, PulseMute::where('user_id', $me->id)->count());
        $this->assertSame(0, PulseSave::where('user_id', $me->id)->count());
        $this->assertSame(0, PulseNotificationPref::where('user_id', $me->id)->count());
        $this->assertSame(0, PulseBlock::where('blocker_id', $me->id)->count());
        $this->assertSame(1, PulseBlock::where('blocked_id', $me->id)->count(), 'blocks against me stay, so an abuser cannot reset by deleting');
        $this->assertSame('closed', $openByMe->fresh()->status);
        $this->assertSame('closed', $line->fresh()->status);

        // Audit rows stay, with me taken out of them.
        $this->assertSame(0, PulseAuditEntry::where('actor_id', $me->id)->count());
        $this->assertGreaterThan(0, PulseAuditEntry::whereNull('actor_id')->where('action', 'line.accept')->count());
        $this->assertNotNull(PulseAuditEntry::where('action', 'privacy.erase')->first());

        // My export afterwards holds nothing personal.
        $after = PrivacyExport::forUser($me);
        $this->assertNull($after['profile']['headline']);
        $this->assertSame([], $after['mutes']);
        $this->assertSame([], $after['activity_log']);
    }

    public function test_erase_keeps_a_page_that_has_no_other_owner_and_says_so(): void
    {
        $me = $this->person();
        $solo = $this->page($me, ['slug' => 'solo-page']);
        $shared = $this->page($me);
        $this->member($shared, null, 'owner');

        $result = PrivacyEraser::erase($me);

        $this->assertSame(['solo-page'], $result['pages_needing_an_owner']);
        $this->assertDatabaseHas('pulse_entity_members', ['entity_id' => $solo->id, 'user_id' => $me->id]);
        $this->assertDatabaseMissing('pulse_entity_members', ['entity_id' => $shared->id, 'user_id' => $me->id]);
    }

    public function test_erase_keeps_confirmed_outcomes_for_the_other_side_and_unlinks_review_authorship(): void
    {
        $customer = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);
        $c = $this->openLine($customer, $page);
        app(OutcomeService::class)->propose($customer, $c, 'won');
        app(OutcomeService::class)->confirm($owner, $c->fresh());
        app(ReviewService::class)->submit($customer, $c->fresh(), ['rating' => 5, 'body' => 'Great work.']);

        PrivacyEraser::erase($customer);

        $this->assertTrue(OutcomeService::confirmed($c->fresh()), 'the page\'s result stays');
        $this->assertTrue(PulseReview::first()->pseudonymous);
    }
}
