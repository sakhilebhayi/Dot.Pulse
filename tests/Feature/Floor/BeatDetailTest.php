<?php

namespace Tests\Feature\Floor;

use App\Livewire\Pulse\Floor\BeatDetail;
use App\Livewire\Pulse\Floor\Safety;
use App\Livewire\Pulse\ReportButton;
use App\Models\PulseBlock;
use App\Models\PulseEntityBlock;
use App\Models\PulseMute;
use App\Models\PulseOffer;
use App\Models\PulsePost;
use App\Models\PulseReport;
use App\Models\PulseSignal;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class BeatDetailTest extends FloorTestCase
{
    private function detail(PulseSignal $signal, $as = null)
    {
        return Livewire::actingAs($as ?? $this->me)->test(BeatDetail::class, ['signal' => $signal]);
    }

    private function anonymousTalent(): array
    {
        $this->dEntity($this->me, ['name' => 'My Employer Page', 'is_employer' => true]);
        $pro = $this->person(['discoverability' => 'anonymous', 'skills' => ['payroll'], 'experience_years' => 7, 'place' => $this->geo->jhb], ['name' => 'Naledi Secretname', 'email' => 'naledi.secret@example.test']);
        $profileId = DB::table('pulse_profiles')->where('user_id', $pro->id)->value('id');
        $signal = $this->dSignal([
            'kind' => 'talent', 'subject_type' => 'profile', 'subject_id' => $profileId, 'owner_id' => $pro->id, 'anonymous' => true,
            'title' => 'Payroll specialist', 'skills' => ['payroll'], 'topics' => ['payroll'], 'place' => $this->geo->jhb,
        ]);
        $this->dMatch($this->me, $signal, 55, [['code' => 'role_talent', 'text' => 'They have skills you are hiring for: payroll.', 'weight' => 38, 'ref' => 'payroll']]);

        return [$signal, $pro];
    }

    public function test_the_page_route_needs_a_visible_signal(): void
    {
        [$signal] = $this->offerFor('Bookkeeping for small shops');

        $this->actingAs($this->me)->get(route('signals.show', $signal))->assertOk()->assertSee('Bookkeeping for small shops');
        $this->actingAs($this->me)->get('/s/99999')->assertNotFound();
        $this->actingAs($this->me)->get('/s/abc')->assertNotFound();
    }

    public function test_a_matched_only_need_opens_only_for_the_people_it_was_matched_to(): void
    {
        $owner = $this->person();
        $need = $this->dNeed($owner, ['title' => 'Private geyser need', 'visibility' => 'matched', 'details' => 'Budget details only matched people may read']);
        $signal = $this->dSignal(['kind' => 'need', 'subject_type' => 'need', 'subject_id' => $need->id, 'owner_id' => $owner->id, 'title' => $need->title, 'topics' => ['plumbing'], 'place' => $this->geo->jhb]);

        // Not matched to $this->me: a 404, exactly like a need that does not exist.
        $this->actingAs($this->me)->get(route('signals.show', $signal))->assertNotFound();

        $this->dMatch($this->me, $signal, 60, [['code' => 'offer_need', 'text' => 'You offer plumbing, they need plumbing.', 'weight' => 40, 'ref' => 'plumbing']]);
        $this->actingAs($this->me)->get(route('signals.show', $signal))->assertOk()->assertSee('Private geyser need');

        // An open need stays readable by any signed-in person who passes the ordinary checks.
        $need->forceFill(['visibility' => 'open'])->save();
        $stranger = $this->person(['place' => $this->geo->jhb]);
        $this->actingAs($stranger)->get(route('signals.show', $signal))->assertOk();
    }

    public function test_an_offer_is_shown_in_full_with_every_reason_and_why_it_is_here(): void
    {
        [$signal, $entity] = $this->offerFor('Bookkeeping for small shops');
        PulseOffer::where('id', $signal->subject_id)->update(['description' => "Books closed by the 7th.\nVAT201 filed on time.", 'price_mode' => 'fixed', 'amount_cents' => 120000, 'unit' => 'per month', 'accepts_quotes' => true]);

        $this->detail($signal->fresh())
            ->assertSee('Bookkeeping for small shops')
            ->assertSee('Books closed by the 7th.')
            ->assertSee('VAT201 filed on time.')
            ->assertSee('Takes quote requests')
            ->assertSee('Why you are seeing this')
            ->assertSee('You need bookkeeping, they offer bookkeeping')
            ->assertSee('Both in Johannesburg')
            ->assertSee('Posted today')
            ->assertSee('the 3 reasons above')
            ->assertSee('does not use race, gender, age')
            ->assertSee('Stop using this')
            ->assertSee('R1 200', false)
            ->assertSee('Johannesburg');
    }

    public function test_the_from_entity_ladder_is_drawn_from_real_checks_and_says_what_is_only_claimed(): void
    {
        [$signal, $entity] = $this->offerFor();
        $entity->forceFill(['registration_number' => '2019/123456/07', 'verification_status' => 'claimed'])->save();

        $this->detail($signal)
            ->assertSee('Who it comes from')
            ->assertSee('Registration number')
            ->assertSee('Claimed by them, not checked')
            ->assertDontSee('Time on Pulse')
            ->assertDontSee('Verified business');
    }

    public function test_response_record_is_honest_when_there_is_none_and_counted_when_there_is(): void
    {
        [$signal] = $this->offerFor();

        $this->detail($signal)->assertSee('No first messages received yet, so there is no response record.');

        $this->assertSame(
            'Answered 3 of 4 first messages in the last 90 days (75 percent). Usually within 2 hours.',
            BeatDetail::answerLine(['received' => 4, 'answered' => 3, 'response_rate' => 0.75, 'median_first_response_seconds' => 7200, 'window_days' => 90]),
        );
        $this->assertSame(
            'Answered 1 of 1 first message in the last 90 days (100 percent). Usually within 20 minutes.',
            BeatDetail::answerLine(['received' => 1, 'answered' => 1, 'response_rate' => 1.0, 'median_first_response_seconds' => 1200, 'window_days' => 90]),
        );
        $this->assertSame('Answered 1 of 1 first message in the last 1 day (100 percent). Usually under a minute.', BeatDetail::answerLine(['received' => 1, 'answered' => 1, 'response_rate' => 1.0, 'median_first_response_seconds' => 20, 'window_days' => 1]));
        $this->assertStringContainsString('2 days', BeatDetail::answerLine(['received' => 2, 'answered' => 2, 'response_rate' => 1.0, 'median_first_response_seconds' => 172800, 'window_days' => 90]));
    }

    public function test_trade_beats_carry_safe_handover_guidance_and_work_beats_carry_the_work_version(): void
    {
        [$signal] = $this->offerFor();

        $this->detail($signal)
            ->assertSee('Before you pay or meet')
            ->assertSee('Pay inside your own banking app.')
            ->assertSee('Guidance, not protection.');

        $owner = $this->person();
        $entity = $this->dEntity($owner, ['name' => 'Employer Co', 'is_employer' => true]);
        $role = $this->dRole($entity, ['title' => 'Payroll administrator', 'pay_text' => 'R28 000 a month', 'employment_type' => 'full_time', 'description' => 'Run monthly payroll.']);
        $roleSignal = $this->dSignal(['kind' => 'role', 'subject_type' => 'role', 'subject_id' => $role->id, 'entity_id' => $entity->id, 'owner_id' => $owner->id, 'title' => 'Payroll administrator']);

        $this->detail($roleSignal)
            ->assertSee('Before you share or sign')
            ->assertSee('A real employer never asks you to pay')
            ->assertSee('Run monthly payroll.')
            ->assertSee('Full time')
            ->assertSee('R28 000 a month')
            ->assertDontSee('Pay inside your own banking app.');
    }

    public function test_a_beat_without_a_match_says_so_instead_of_inventing_reasons(): void
    {
        $s = $this->dSignal(['owner_id' => $this->person()->id, 'kind' => 'ask', 'subject_type' => 'post', 'title' => 'An unmatched ask']);

        $this->detail($s)->assertSee('No match put this in front of you.')->assertDontSee('Stop using this');
    }

    public function test_an_anonymous_professional_page_is_the_card_and_nothing_that_names_the_person(): void
    {
        [$signal, $pro] = $this->anonymousTalent();

        $c = $this->detail($signal)
            ->assertSee('Anonymous professional')
            ->assertSee('Payroll specialist')
            ->assertSee('Payroll')
            ->assertSee('5 to 10 years')
            ->assertSee('Gauteng')
            ->assertSee('This is all an employer sees.')
            ->assertDontSee('Naledi')
            ->assertDontSee('Secretname')
            ->assertDontSee('naledi.secret')
            ->assertDontSee('How Naledi')
            ->assertDontSee('Block ')
            ->assertDontSee('Mute ');

        $this->assertSame([], $c->get('rungs'));
        $this->assertNull($c->get('stats'), 'no response record is looked up for an anonymous person');
        $this->assertStringNotContainsString('Naledi', $c->html());
        $this->assertStringNotContainsString('naledi.secret', $c->html());
        $this->assertStringNotContainsString((string) $pro->email, json_encode($c->get('beats')));
    }

    public function test_an_anonymous_professional_is_invisible_to_anyone_who_is_not_an_employer(): void
    {
        [$signal] = $this->anonymousTalent();
        $plain = $this->person();

        $this->actingAs($plain)->get(route('signals.show', $signal))->assertNotFound();
    }

    public function test_held_removed_expired_blocked_and_muted_things_are_a_404_exactly_like_missing_ones(): void
    {
        $held = $this->dSignal(['owner_id' => $this->person()->id, 'kind' => 'ask', 'subject_type' => 'post', 'status' => 'held']);
        $removed = $this->dSignal(['owner_id' => $this->person()->id, 'kind' => 'ask', 'subject_type' => 'post', 'status' => 'removed']);
        $expired = $this->dSignal(['owner_id' => $this->person()->id, 'kind' => 'ask', 'subject_type' => 'post', 'expires_at' => now()->subMinute()]);
        $blockedUser = $this->person();
        $blocked = $this->dSignal(['owner_id' => $blockedUser->id, 'kind' => 'ask', 'subject_type' => 'post']);
        $this->dBlock($this->me, $blockedUser);
        $muted = $this->dSignal(['owner_id' => $this->person()->id, 'kind' => 'ask', 'subject_type' => 'post', 'topics' => ['cycling']]);
        $this->dMute($this->me, 'topic', 'cycling');

        foreach ([$held, $removed, $expired, $blocked, $muted] as $s) {
            $this->actingAs($this->me)->get(route('signals.show', $s))->assertNotFound();
        }
    }

    public function test_a_person_can_open_their_own_live_signal_and_is_told_it_is_theirs(): void
    {
        $s = $this->dSignal(['owner_id' => $this->me->id, 'kind' => 'ask', 'subject_type' => 'post', 'title' => 'My own ask']);

        $this->detail($s)->assertSee('My own ask')->assertSee('This is yours.')->assertDontSee('data-pk-dialog', false)->assertDontSee('>Save<', false)->assertDontSee('How ');
    }

    public function test_the_answer_heading_reads_as_a_sentence_for_one_person_or_one_business(): void
    {
        $owner = $this->person();
        $s = $this->dSignal(['owner_id' => $owner->id, 'kind' => 'ask', 'subject_type' => 'post', 'title' => 'A door']);
        $this->dMatch($this->me, $s);

        $this->detail($s)->assertSee('How '.$owner->name.' answers')->assertDontSee($owner->name.' answer<', false);

        [$signal] = $this->offerFor();
        $this->detail($signal)->assertSeeHtml('answers</h2>');
    }

    public function test_knock_and_save_are_on_the_page_and_save_works(): void
    {
        [$signal, $entity] = $this->offerFor();

        $c = $this->detail($signal)->assertSee('data-pk-dialog', false);
        $this->assertSame(['entity' => $entity->id], $c->get('beats')[0]['knock']['target']);

        $c->call('save', $signal->id)->assertSee('Saved');
        $this->assertDatabaseHas('pulse_saves', ['user_id' => $this->me->id, 'saveable_id' => $signal->id]);
    }

    public function test_removing_a_reason_from_the_detail_page_updates_it(): void
    {
        $owner = $this->person();
        $entity = $this->dEntity($owner, ['name' => 'Sample Books']);
        $signal = $this->dOfferSignal($entity, ['bookkeeping'], ['title' => 'Books done right', 'place' => $this->geo->jhb]);
        $match = $this->dMatch($this->me, $signal, 60, [
            ['code' => 'topic', 'text' => 'They are into cycling, which you follow.', 'weight' => 24, 'ref' => 'cycling'],
            ['code' => 'place_city', 'text' => 'Both in Johannesburg', 'weight' => 14, 'ref' => (string) $this->geo->jhb->id],
        ]);

        $this->detail($signal)->call('removeReason', $match->id, 'topic')->assertDontSee('They are into cycling, which you follow.')->assertSee('Books done right');

        $this->assertDatabaseHas('pulse_mutes', ['user_id' => $this->me->id, 'kind' => 'topic', 'target' => 'cycling']);
    }

    public function test_reporting_a_listing_goes_through_the_report_service_once_per_person(): void
    {
        [$signal] = $this->offerFor();

        $c = Livewire::actingAs($this->me)->test(Safety::class, ['signalId' => $signal->id]);
        $c->call('report')->assertHasErrors(['reason']);
        $c->set('reason', 'spam')->set('note', 'Looks like a scam')->call('report')->assertHasNoErrors()->assertSet('reported', true)->assertDispatched('toast');

        $this->assertSame(1, PulseReport::count());
        $report = PulseReport::first();
        $this->assertSame($this->me->id, $report->reporter_id);
        $this->assertSame(PulseSignal::class, $report->reportable_type);
        $this->assertSame('spam', $report->reason);

        Livewire::actingAs($this->me)->test(Safety::class, ['signalId' => $signal->id])->set('reason', 'spam')->call('report');
        $this->assertSame(1, PulseReport::count(), 'one report per person per thing');
    }

    public function test_other_requires_a_note_when_reporting(): void
    {
        [$signal] = $this->offerFor();

        Livewire::actingAs($this->me)->test(Safety::class, ['signalId' => $signal->id])->set('reason', 'other')->call('report')->assertHasErrors(['note']);
        $this->assertSame(0, PulseReport::count());
    }

    public function test_muting_and_blocking_aim_at_the_business_behind_the_listing(): void
    {
        [$signal, $entity] = $this->offerFor();

        Livewire::actingAs($this->me)->test(Safety::class, ['signalId' => $signal->id])
            ->assertSee('Mute '.$entity->name)
            ->assertSee('Block '.$entity->name)
            ->call('mute')
            ->assertDispatched('toast');
        $this->assertDatabaseHas('pulse_mutes', ['user_id' => $this->me->id, 'kind' => 'entity', 'target' => (string) $entity->id]);

        Livewire::actingAs($this->me)->test(Safety::class, ['signalId' => $signal->id])->call('block')->assertRedirect(route('floor'));
        $this->assertSame(1, PulseEntityBlock::where('entity_id', $entity->id)->where('user_id', $this->me->id)->count());
    }

    public function test_muting_and_blocking_a_personal_listing_aim_at_the_person(): void
    {
        $author = $this->person(['place' => $this->geo->jhb], ['name' => 'Lerato Author']);
        $need = $this->dNeed($author, ['title' => 'Designer wanted']);
        $signal = PulseSignal::where('subject_type', 'need')->where('subject_id', $need->id)->first() ?? $this->dSignal(['kind' => 'need', 'subject_type' => 'need', 'subject_id' => $need->id, 'owner_id' => $author->id, 'title' => 'Designer wanted']);

        Livewire::actingAs($this->me)->test(Safety::class, ['signalId' => $signal->id])->assertSee('Mute Lerato Author')->call('mute');
        $this->assertDatabaseHas('pulse_mutes', ['user_id' => $this->me->id, 'kind' => 'user', 'target' => (string) $author->id]);

        Livewire::actingAs($this->me)->test(Safety::class, ['signalId' => $signal->id])->call('block')->assertRedirect(route('floor'));
        $this->assertSame(1, PulseBlock::where('blocker_id', $this->me->id)->where('blocked_id', $author->id)->count());
    }

    public function test_an_anonymous_professional_can_be_reported_but_never_muted_or_blocked_by_name(): void
    {
        [$signal, $pro] = $this->anonymousTalent();

        $c = Livewire::actingAs($this->me)->test(Safety::class, ['signalId' => $signal->id]);
        $c->assertSee('Report')->assertDontSee('Mute')->assertDontSee('Block')->call('mute')->call('block');

        $this->assertSame(0, PulseMute::count());
        $this->assertSame(0, PulseBlock::count());

        $c->set('reason', 'harassment')->call('report')->assertHasNoErrors();
        $this->assertSame(1, PulseReport::count());
    }

    public function test_a_person_cannot_report_their_own_listing(): void
    {
        $s = $this->dSignal(['owner_id' => $this->me->id, 'kind' => 'ask', 'subject_type' => 'post']);

        Livewire::actingAs($this->me)->test(Safety::class, ['signalId' => $s->id])->assertDontSee('Report');
    }

    public function test_an_ask_from_a_post_reuses_the_existing_report_button(): void
    {
        $author = $this->person();
        $post = PulsePost::factory()->create(['user_id' => $author->id, 'type' => 'question', 'title' => 'Which payroll tools cope with leave?', 'body' => 'We run South African leave rules.']);
        $signal = PulseSignal::where('subject_type', 'post')->where('subject_id', $post->id)->first()
            ?? $this->dSignal(['kind' => 'ask', 'subject_type' => 'post', 'subject_id' => $post->id, 'owner_id' => $author->id, 'title' => 'Which payroll tools cope with leave?']);

        $this->detail($signal)
            ->assertSee('Which payroll tools cope with leave?')
            ->assertSee('We run South African leave rules.')
            ->assertSee('Read the whole post')
            ->assertSeeLivewire(ReportButton::class);
    }
}
