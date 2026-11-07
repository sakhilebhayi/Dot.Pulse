<?php

namespace Tests\Feature\LinesUi;

use App\Livewire\Pulse\Lines\Thread;
use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\User;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\OutcomeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class ThreadTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private function lines(): LineService
    {
        return app(LineService::class);
    }

    /**
     * An open enquiry line between a sender and a page about an offer.
     *
     * @return array{0: User, 1: User, 2: PulseEntity, 3: PulseConversation} sender, page owner, page, line
     */
    private function enquiry(): array
    {
        $owner = $this->person();
        $page = $this->page($owner, ['name' => 'Moyo Plumbing', 'contact_policy' => 'requests']);
        $offer = $this->offer($page, ['title' => 'Geyser repair', 'summary' => 'Same week geyser repairs', 'category' => 'Plumbing']);
        $sender = $this->person(['contact_policy' => 'requests'], ['name' => 'Sipho Ndlovu']);
        $line = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $offer);
        $this->lines()->accept($owner, $line);

        return [$sender, $owner, $page, $line->fresh()];
    }

    /**
     * A direct line between two people, open at once, no context.
     *
     * @return array{0: User, 1: User, 2: PulseConversation} sender, recipient, line
     */
    private function direct(): array
    {
        $owner = $this->policy($this->person([], ['name' => 'Naledi Dube']), 'anyone');
        $other = $this->person([], ['name' => 'Sipho Ndlovu']);
        $line = $this->openLine($other, $owner, 'dm');

        return [$other, $owner, $line];
    }

    private function thread(User $as, PulseConversation $line)
    {
        return Livewire::actingAs($as)->test(Thread::class, ['conversation' => $line]);
    }

    // ------------------------------------------------------------------ page and access

    public function test_a_participant_opens_the_page_and_a_guest_is_sent_to_sign_in(): void
    {
        [$sender, , , $line] = $this->enquiry();

        $this->get("/lines/{$line->id}")->assertRedirect('/login');
        $this->actingAs($sender)->get("/lines/{$line->id}")
            ->assertOk()
            ->assertSeeLivewire(Thread::class)
            ->assertSee('Geyser repair')
            ->assertSee('Moyo Plumbing');
    }

    public function test_a_stranger_gets_a_404_and_so_does_a_page_editor(): void
    {
        [, , $page, $line] = $this->enquiry();
        $editor = $this->member($page, null, 'editor');

        $this->actingAs($this->person())->get("/lines/{$line->id}")->assertNotFound();
        $this->actingAs($editor)->get("/lines/{$line->id}")->assertNotFound();

        Livewire::actingAs($this->person())->test(Thread::class, ['conversation' => $line])->assertNotFound();
    }

    public function test_a_responder_of_the_page_reads_and_writes_in_the_line(): void
    {
        [, , $page, $line] = $this->enquiry();
        $responder = $this->member($page, null, 'responder');

        $this->thread($responder, $line)
            ->assertSee('Geyser repair')
            ->set('body', 'We can come Thursday morning.')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame('We can come Thursday morning.', $line->messages()->latest('id')->first()->body);
    }

    public function test_the_poll_endpoint_marks_read_and_shows_what_is_new(): void
    {
        [$sender, $owner, , $line] = $this->enquiry();
        $component = $this->thread($sender, $line)->assertDontSee('Is the part in stock?');

        $this->travel(2)->seconds();
        $this->lines()->send($owner, $line->fresh(), 'Is the part in stock?');
        $this->assertTrue($this->lines()->present($sender, $line->fresh())['unread']);

        $component->call('refresh')->assertSee('Is the part in stock?');

        $this->assertFalse($this->lines()->present($sender, $line->fresh())['unread']);
        $this->assertStringContainsString('wire:poll.visible.10s="refresh"', $component->html());
    }

    public function test_opening_a_line_marks_it_read(): void
    {
        [$sender, $owner, , $line] = $this->enquiry();
        $this->travel(2)->seconds();
        $this->lines()->send($owner, $line->fresh(), 'Morning. When suits you?');
        $this->assertTrue($this->lines()->present($sender, $line->fresh())['unread']);

        $this->thread($sender, $line->fresh());

        $this->assertFalse($this->lines()->present($sender, $line->fresh())['unread']);
    }

    // ------------------------------------------------------------------ the context card

    public function test_the_context_card_shows_the_offer_the_four_fields_and_the_first_message_as_a_card(): void
    {
        [$sender, , , $line] = $this->enquiry();

        $this->thread($sender, $line)
            ->assertSee('What this line is about')
            ->assertSee('Same week geyser repairs')
            ->assertSee('Plumbing')
            ->assertSee('The request')
            ->assertSee('Fix a leaking geyser')
            ->assertSee('This Thursday')
            ->assertSee('Soweto')
            ->assertSee('R1000 to R3000')
            ->assertSee('First message')
            ->assertSee('They said yes. Contact details can be shared now.', false);
    }

    public function test_a_live_subject_links_and_a_dead_one_says_so(): void
    {
        Route::get('/b/{entity}/o/{offer}', fn () => 'offer')->name('offers.show');
        [$sender, , , $line] = $this->enquiry();

        $this->thread($sender, $line)->assertSee('See the live offer');

        DB::table('pulse_offers')->where('id', $line->context_id)->update(['status' => 'paused']);

        $this->thread($sender, $line)
            ->assertDontSee('See the live offer')
            ->assertSee('This is how it read when the line started. It is no longer live.');
    }

    // ------------------------------------------------------------------ composer and the link rule

    public function test_a_person_sends_a_message_and_it_lands_in_the_thread(): void
    {
        [$sender, , , $line] = $this->enquiry();

        $this->thread($sender, $line)
            ->set('body', 'I can send a photo of the leak.')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('body', '')
            ->assertSee('I can send a photo of the leak.')
            ->assertDispatched('composer-sent');
    }

    public function test_an_empty_message_is_refused_in_plain_words(): void
    {
        [$sender, , , $line] = $this->enquiry();

        $this->thread($sender, $line)->set('body', '   ')->call('send')->assertHasErrors('body');
    }

    public function test_the_character_limit_is_enforced(): void
    {
        [$sender, , , $line] = $this->enquiry();
        $max = (int) config('pulse.lines.message_max');

        $this->thread($sender, $line)
            ->assertSee('0 / '.$max)
            ->set('body', str_repeat('a', $max + 1))
            ->call('send')
            ->assertHasErrors('body');
    }

    public function test_links_stay_locked_for_the_sender_until_the_other_side_replies_and_the_page_says_so(): void
    {
        [$sender, $recipient, $line] = $this->direct();

        $component = $this->thread($sender, $line)
            ->assertSee('No links or contact details until they reply')
            ->assertSee('Photos unlock once they reply');

        $component->set('body', 'Call me on 082 555 0100 or see https://example.com/leak')->call('send')->assertHasErrors('body');
        $component->set('body', 'Plain words are fine.')->call('send')->assertHasNoErrors();

        // They reply: links unlock for both.
        $this->thread($recipient, $line->fresh())->set('body', 'Here is my number: 082 555 0199')->call('send')->assertHasNoErrors();

        $this->thread($sender, $line->fresh())
            ->assertSee('You can share links and contact details now.')
            ->assertSee('Add a photo')
            ->set('body', 'Thanks, see https://example.com/leak')
            ->call('send')
            ->assertHasNoErrors();
    }

    public function test_a_photo_can_be_added_once_they_have_replied(): void
    {
        Storage::fake('local');
        config(['pulse.entities.media.disk' => 'local']);
        [$sender, $recipient, $line] = $this->direct();
        $this->lines()->send($recipient, $line->fresh(), 'Send a picture of it.');

        $this->thread($sender, $line->fresh())
            ->set('image', UploadedFile::fake()->image('leak.jpg', 600, 400))
            ->set('body', 'Here it is.')
            ->call('send')
            ->assertHasNoErrors();

        $message = $line->messages()->latest('id')->first();
        $this->assertNotNull($message->media_id);
        $this->assertSame('Here it is.', $message->body);
    }

    public function test_something_that_is_not_a_photo_is_refused(): void
    {
        [$sender, $recipient, $line] = $this->direct();
        $this->lines()->send($recipient, $line->fresh(), 'Send a picture of it.');

        $this->thread($sender, $line->fresh())
            ->set('image', UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'))
            ->assertHasErrors('image')
            ->assertSet('image', null)
            ->assertSee('Pick a photo: JPEG, PNG or WebP.');
    }

    public function test_a_message_that_goes_too_fast_gets_a_polite_answer_not_an_error_page(): void
    {
        config(['pulse.lines.rate_limits.message' => 1]);
        [$sender, , , $line] = $this->enquiry();

        $this->thread($sender, $line)
            ->set('body', 'First')->call('send')->assertSet('failure', null)
            ->set('body', 'Second')->call('send')
            ->assertSee('You are doing that too quickly. Try again in')
            ->assertSet('body', 'Second');
    }

    public function test_the_composer_is_not_shown_on_a_closed_line_and_nobody_can_post(): void
    {
        [$sender, , , $line] = $this->enquiry();
        $this->lines()->close($sender, $line);

        $this->thread($sender, $line->fresh())
            ->assertSee('This line is over. Nobody can add to it.')
            ->assertDontSee('Write a message', false)
            ->set('body', 'Still there?')
            ->call('send')
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ message kinds

    public function test_system_notes_read_correctly_for_the_side_that_did_the_thing(): void
    {
        [$sender, $owner, , $line] = $this->enquiry();
        app(OutcomeService::class)->propose($owner, $line, 'won');

        $this->thread($owner, $line->fresh())->assertSee('You marked this as won (the deal happened). Waiting for them to confirm.');
        $this->thread($sender, $line->fresh())->assertSee('They marked this as won (the deal happened). Confirm it if it is right.');
    }

    public function test_the_other_sides_messages_can_be_reported_and_mine_cannot(): void
    {
        [$sender, $owner, , $line] = $this->enquiry();
        $message = $this->lines()->send($owner, $line->fresh(), 'Thursday works.');
        $mine = $this->lines()->send($sender, $line->fresh(), 'Great, 9 am?');

        $component = $this->thread($sender, $line->fresh());
        $html = $component->html();
        $this->assertStringContainsString('reportMessage('.$message->id.')', $html);
        $this->assertStringNotContainsString('reportMessage('.$mine->id.')', $html);

        $component->call('reportMessage', $message->id)->assertSeeLivewire('pulse.lines.report');
    }

    // ------------------------------------------------------------------ handover guidance

    public function test_trade_lines_show_the_handover_words_as_guidance_and_not_protection(): void
    {
        [$sender, , , $line] = $this->enquiry();

        $component = $this->thread($sender, $line);
        foreach (config('pulse.safety.trade') as $tip) {
            $component->assertSee($tip);
        }
        $component->assertSee('guidance, not protection')
            ->assertSee('Got it, hide this');
        $this->assertStringContainsString('pulse-handover-'.$line->id, $component->html());
    }

    public function test_a_plain_message_line_has_no_handover_banner(): void
    {
        [$sender, , $line] = $this->direct();

        $this->thread($sender, $line)->assertDontSee('Before you hand anything over');
    }

    public function test_job_lines_get_the_work_guidance(): void
    {
        $page = $this->page(null, ['name' => 'Acme Works', 'contact_policy' => 'anyone']);
        $role = $this->role($page);
        $applicant = $this->person([], ['name' => 'Lerato Khumalo']);
        $line = $this->lines()->knock($applicant, ['entity' => $page->id], 'application', $this->card(['what' => 'I would like to apply']), $role);

        $this->thread($applicant, $line)->assertSee(config('pulse.safety.work')[0]);
    }

    // ------------------------------------------------------------------ requests answered on the line page

    public function test_a_request_is_answered_on_its_own_page(): void
    {
        $owner = $this->person();
        $page = $this->page($owner, ['name' => 'Moyo Plumbing', 'contact_policy' => 'requests']);
        $offer = $this->offer($page);
        $sender = $this->person(['contact_policy' => 'requests']);
        $line = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $offer);

        $this->thread($sender, $line)->assertSee('Waiting for them')->assertDontSee('Accept');

        $this->thread($owner, $line)
            ->assertSee('Waiting for your answer')
            ->assertSee('Not now')
            ->call('accept', $line->id)
            ->assertDispatched('toast');

        $this->assertSame('open', $line->fresh()->status);
    }

    public function test_not_now_from_the_line_page_tells_the_sender_the_short_reason(): void
    {
        $owner = $this->person();
        $page = $this->page($owner, ['name' => 'Moyo Plumbing', 'contact_policy' => 'requests']);
        $sender = $this->person(['contact_policy' => 'requests']);
        $line = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $this->offer($page));

        $this->thread($owner, $line)
            ->call('chooseDecline', $line->id)
            ->set('declineReason', 'out_of_area')
            ->call('decline');

        $this->thread($sender, $line->fresh())->assertSee('Outside my area')->assertSee('That is an answer, not a verdict');
    }

    public function test_a_lapsed_request_reads_closed_no_reply_to_the_sender(): void
    {
        $owner = $this->person();
        $page = $this->page($owner, ['name' => 'Moyo Plumbing', 'contact_policy' => 'requests']);
        $sender = $this->person(['contact_policy' => 'requests']);
        $line = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $this->offer($page));
        $line->forceFill(['created_at' => now()->subDays(15)])->save();
        $this->artisan('pulse:lines:sweep');

        $this->thread($sender, $line->fresh())->assertSee('Closed, no reply');
    }

    public function test_a_line_waiting_for_your_reply_says_for_how_long_and_offers_the_honest_options(): void
    {
        [$sender, $owner, , $line] = $this->enquiry();
        $this->lines()->send($owner, $line->fresh(), 'Can you do Thursday?');
        $this->travel(3)->days();

        $this->thread($sender, $line->fresh())
            ->assertSee('Waiting for your reply for 3 days')
            ->assertSee('Reply below, or close the line');
    }

    // ------------------------------------------------------------------ header actions

    public function test_mute_and_archive_are_remembered_per_person(): void
    {
        [$sender, $owner, , $line] = $this->enquiry();

        $this->thread($sender, $line)->call('toggleMute')->assertSee('Unmute')->call('toggleArchive')->assertSee('Unarchive');

        $this->assertTrue((bool) DB::table('pulse_conversation_user')->where(['pulse_conversation_id' => $line->id, 'user_id' => $sender->id])->value('muted'));
        $this->assertNotNull(DB::table('pulse_conversation_user')->where(['pulse_conversation_id' => $line->id, 'user_id' => $sender->id])->value('archived_at'));
        $this->assertFalse((bool) DB::table('pulse_conversation_user')->where(['pulse_conversation_id' => $line->id, 'user_id' => $owner->id])->value('muted'));

        $this->thread($sender, $line)->call('toggleMute')->assertSee('Mute');
    }

    public function test_closing_a_line_asks_first_then_closes_it(): void
    {
        [$sender, , , $line] = $this->enquiry();

        $this->thread($sender, $line)
            ->call('ask', 'close')
            ->assertSee('Close this line?')
            ->call('cancelAsk')
            ->assertDontSee('Close this line?')
            ->call('ask', 'close')
            ->call('closeThis');

        $this->assertSame('closed', $line->fresh()->status);
    }

    public function test_blocking_asks_first_and_then_ends_the_contact_without_telling_them(): void
    {
        [$sender, $owner, , $line] = $this->enquiry();

        $this->thread($owner, $line)
            ->call('askBlock', $line->id)
            ->assertSee('They are not told')
            ->call('confirmBlock');

        $this->assertNotSame('open', $line->fresh()->status);
        $this->thread($sender, $line->fresh())->assertDontSee('blocked');
    }

    // ------------------------------------------------------------------ anonymous invitations

    /** @return array{0: User, 1: User, 2: PulseEntity, 3: PulseConversation} employer, professional, page, line */
    private function anonymousInvite(): array
    {
        [$pro, $signal] = $this->talent('anonymous');
        $employer = $this->person([], ['name' => 'Zanele Mokoena']);
        $page = $this->page($employer, ['name' => 'Acme Works', 'is_employer' => true, 'contact_policy' => 'anyone']);
        $line = $this->lines()->knock($employer, ['signal' => $signal->id, 'as_entity' => $page->id], 'invite', ['what' => 'We have a bookkeeping role open']);

        return [$employer, $pro, $page, $line];
    }

    public function test_the_employer_sees_an_alias_and_a_card_and_never_the_professionals_name_email_or_avatar(): void
    {
        [$employer, $pro, , $line] = $this->anonymousInvite();
        $this->lines()->accept($pro, $line);
        $this->lines()->send($pro, $line->fresh(), 'Happy to talk about the role.');
        $this->lines()->send($employer, $line->fresh(), 'Great. Tell me about payroll.');

        $component = $this->thread($employer, $line->fresh());
        $component->assertSee('Anonymous until they show who they are')
            ->assertSee('Bookkeeper, 7 years, payroll and VAT')
            ->assertSee('6-10 years')
            ->assertSee('Gauteng')
            ->assertSee('Happy to talk about the role.');

        $html = $component->html();
        foreach (['Thandiwe', 'Zulu-Private', $pro->email, 'thandi.private', (string) $pro->profile_photo_path] as $secret) {
            if ($secret !== '') {
                $this->assertStringNotContainsString($secret, $html, "leaked: {$secret}");
            }
        }
        $this->assertStringNotContainsString('user_id', $html);
        $this->assertStringNotContainsString('"recipient_id"', $html);
        $this->assertMatchesRegularExpression('/Professional [0-9A-F]{4}/', $html);
    }

    public function test_the_employer_cannot_reveal_and_only_the_professional_can(): void
    {
        [$employer, $pro, , $line] = $this->anonymousInvite();
        $this->lines()->accept($pro, $line);

        $this->thread($employer, $line->fresh())->assertDontSee('Show who I am')->call('reveal')->assertForbidden();
        $this->assertTrue($line->fresh()->identity_hidden);

        $this->thread($pro, $line->fresh())
            ->assertSee('Show who I am')
            ->assertSee('This employer sees you as an anonymous card')
            ->call('ask', 'reveal')
            ->assertSee('This cannot be undone')
            ->call('reveal')
            ->assertDispatched('toast');

        $this->assertFalse($line->fresh()->identity_hidden);
        $this->thread($employer, $line->fresh())->assertSee('Thandiwe Zulu-Private');
    }

    public function test_the_employer_cannot_block_an_anonymous_professional_by_name(): void
    {
        [$employer, $pro, , $line] = $this->anonymousInvite();
        $this->lines()->accept($pro, $line);

        $this->thread($employer, $line->fresh())->call('askBlock', $line->id)->call('confirmBlock')
            ->assertSee('You cannot block someone whose name you have not been shown');
    }

    public function test_the_professional_sees_the_employer_page_by_name_and_can_block_it(): void
    {
        [, $pro, $page, $line] = $this->anonymousInvite();

        $this->thread($pro, $line)->assertSee('Acme Works')->assertSee('Needs your answer', false);
    }
}
