<?php

namespace Tests\Feature\Lines;

use App\Models\PulseConversation;
use App\Models\PulseMessage;
use App\Services\Pulse\Lines\KnockBudget;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\LineService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class LineServiceTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private function lines(): LineService
    {
        return app(LineService::class);
    }

    public function test_a_knock_on_a_listing_creates_a_request_with_a_context_snapshot(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'context']);
        $offer = $this->offer($page, ['title' => 'Geyser repair', 'price_mode' => 'from', 'amount_cents' => 85000]);

        $c = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $offer);

        $this->assertSame('requested', $c->status);
        $this->assertSame('offer', $c->context_type);
        $this->assertSame('Geyser repair', $c->context_title);
        $this->assertSame('From R850', $c->context_snapshot['price']);
        $this->assertSame($sender->id, $c->initiator_id);
        $this->assertSame($page->id, $c->entity_id);
        $this->assertSame('Fix a leaking geyser', $c->card['what']);
        $this->assertSame(1, PulseMessage::where('pulse_conversation_id', $c->id)->count());
        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'line.knock', 'actor_id' => $sender->id]);
    }

    public function test_the_context_snapshot_does_not_change_when_the_listing_changes(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'context']);
        $offer = $this->offer($page, ['title' => 'Geyser repair']);

        $c = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $offer);
        $offer->update(['title' => 'Something else entirely', 'status' => 'archived']);

        $this->assertSame('Geyser repair', $c->fresh()->context_title);
        $this->assertSame('Geyser repair', $c->fresh()->context_snapshot['title']);
    }

    public function test_a_card_has_four_text_fields_and_unknown_fields_are_refused(): void
    {
        $sender = $this->person();
        $page = $this->page();

        $this->expectException(ValidationException::class);
        $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(['notes' => 'a fifth field']));
    }

    public function test_the_card_needs_a_what_and_caps_the_length(): void
    {
        $sender = $this->person();
        $page = $this->page();

        try {
            $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', ['what' => '']);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('card.what', $e->errors());
        }

        try {
            $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(['where' => str_repeat('a', 81)]));
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('card.where', $e->errors());
        }
    }

    #[DataProvider('contactDetails')]
    public function test_links_and_contact_details_are_refused_in_a_first_message(string $text): void
    {
        $sender = $this->person();
        $page = $this->page();

        try {
            $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(['what' => $text]));
            $this->fail("should have refused: {$text}");
        } catch (ValidationException $e) {
            $this->assertStringContainsString('unlock once they reply', $e->errors()['card.what'][0]);
        }

        $this->assertSame(0, PulseConversation::count());
    }

    public static function contactDetails(): array
    {
        return [
            'url' => ['see https://shady.example/pay'],
            'www' => ['look at www.mybusiness.co.za please'],
            'bare domain' => ['mybusiness.co.za has details'],
            'email' => ['write to bob@gmail.com'],
            'obfuscated email' => ['bob at gmail dot com'],
            'za mobile' => ['call me on 082 123 4567'],
            'intl' => ['+27 82 123 4567'],
            'whatsapp' => ['message me on whatsapp'],
            'handle' => ['follow @bobsplumbing'],
        ];
    }

    public function test_budgets_dates_and_quantities_are_not_mistaken_for_phone_numbers(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);

        $c = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card([
            'what' => 'Need 12 bags of cement by 2026-11-20',
            'budget_band' => 'R5000-R10000',
            'when_or_qty' => '2026-11-20 at 14:00',
        ]));

        $this->assertSame('requested', $c->status);
    }

    public function test_html_is_stripped_from_the_card(): void
    {
        $c = $this->lines()->knock($this->person(), ['entity' => $this->page()->id], 'enquiry', $this->card(['what' => '<b>Fix</b> a <script>x</script>geyser']));

        $this->assertSame('Fix a xgeyser', $c->card['what']);
    }

    public function test_contact_details_unlock_after_accept(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);

        $c = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card());

        // Before: the sender cannot send at all (not open), and cannot get around it.
        try {
            $this->lines()->send($sender, $c, 'hello');
            $this->fail('a requested line is not open');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->lines()->accept($owner, $c);
        $c->refresh();
        $this->assertSame('open', $c->status);
        $this->assertNotNull($c->first_response_at);

        $msg = $this->lines()->send($sender, $c, 'Call me on 082 123 4567 or see https://example.org/photo');
        $this->assertStringContainsString('082 123 4567', $msg->body);
    }

    public function test_a_direct_line_still_holds_links_back_until_the_other_side_replies(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'anyone']);

        $c = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card());
        $this->assertSame('open', $c->status, 'anyone policy opens the line directly');

        try {
            $this->lines()->send($sender, $c, 'my number is 0821234567');
            $this->fail('first contact must not carry contact details');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
        }

        $this->lines()->send($sender, $c, 'Can you come on Thursday?');
        $this->lines()->send($this->responderOf($page), $c, 'Yes, Thursday works.');
        $this->assertNotNull($c->fresh()->first_response_at);

        $this->lines()->send($sender, $c->fresh(), 'Great, 0821234567');
        $this->assertTrue(true);
    }

    public function test_accept_decline_and_ignore_are_for_the_recipient_only(): void
    {
        $sender = $this->person();
        $stranger = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);
        $editor = $this->member($page, null, 'editor');
        $responder = $this->member($page, null, 'responder');

        $c = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card());

        foreach ([$sender, $stranger, $editor] as $nope) {
            try {
                $this->lines()->accept($nope, $c);
                $this->fail('only the page\'s responders may accept');
            } catch (AuthorizationException) {
                $this->assertSame('requested', $c->fresh()->status);
            }
        }

        $this->lines()->accept($responder, $c);
        $this->assertSame('open', $c->fresh()->status);
    }

    public function test_a_quiet_decline_tells_the_sender_not_now(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);

        $c = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card());
        $this->lines()->decline($owner, $c, 'no_capacity');

        $c->refresh();
        $this->assertSame('declined', $c->status);
        $this->assertNotNull($c->first_response_at);

        $note = $sender->notifications()->latest()->first();
        $this->assertSame('line_declined', $note->data['type']);
        $this->assertStringContainsString('No capacity right now', $note->data['title']);

        $presented = $this->lines()->present($sender, $c);
        $this->assertSame('No capacity right now', $presented['declined_label']);
    }

    public function test_an_unknown_decline_reason_falls_back_to_not_now(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);

        $c = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card());
        $this->lines()->decline($this->responderOf($page), $c, 'you_smell');

        $this->assertSame('not_now', $c->fresh()->decline_reason);
    }

    public function test_ignoring_quietly_tells_the_sender_nothing_and_hides_it_from_requests(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);

        $c = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card());
        $before = $sender->notifications()->count();

        $this->lines()->ignoreQuietly($owner, $c);

        $this->assertNotNull($c->fresh()->ignored_at);
        $this->assertSame('requested', $c->fresh()->status);
        $this->assertSame($before, $sender->notifications()->count());
        $this->assertCount(0, $this->lines()->inbox($owner, ['tab' => 'requests'])->items());
        $this->assertCount(1, $this->lines()->inbox($owner, ['tab' => 'ignored'])->items());
        $this->assertSame('requested', $this->lines()->present($sender, $c)['status']);
    }

    public function test_only_the_sender_can_withdraw_a_request_and_either_side_can_close_an_open_line(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);

        $c = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card());

        try {
            $this->lines()->close($owner, $c);
            $this->fail('the recipient declines, they do not close a request');
        } catch (AuthorizationException) {
        }

        $this->lines()->close($sender, $c);
        $this->assertSame('closed', $c->fresh()->status);
        $this->assertSame('withdrawn', $c->fresh()->closed_reason);

        $open = $this->openLine($sender, $page);
        $this->lines()->close($owner, $open);
        $this->assertSame('closed', $open->fresh()->status);
    }

    public function test_send_authorisation_stranger_participant_non_participant_and_responder(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $c = $this->openLine($sender, $page);
        $stranger = $this->person();
        $editor = $this->member($page, null, 'editor');
        $responder = $this->member($page, null, 'responder');

        foreach ([$stranger, $editor] as $nope) {
            try {
                $this->lines()->send($nope, $c, 'hi');
                $this->fail('non-participants cannot send');
            } catch (AuthorizationException) {
            }
            try {
                $this->lines()->thread($nope, $c);
                $this->fail('non-participants cannot read');
            } catch (AuthorizationException) {
            }
        }

        $this->lines()->send($sender, $c, 'participant');
        $this->lines()->send($responder, $c, 'a responder of the page may answer');
        // the card, the sender's message and the responder's answer
        $this->assertSame(3, PulseMessage::where('pulse_conversation_id', $c->id)->where('kind', 'text')->count());
    }

    public function test_a_closed_line_cannot_be_written_to(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $c = $this->openLine($sender, $page);
        $this->lines()->close($sender, $c);

        $this->expectException(AuthorizationException::class);
        $this->lines()->send($sender, $c->fresh(), 'still there?');
    }

    public function test_messages_are_rate_limited_to_thirty_a_minute(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $c = $this->openLine($sender, $page);

        for ($i = 0; $i < 30; $i++) {
            $this->lines()->send($sender, $c, "message {$i}");
        }

        $this->expectException(ThrottleRequestsException::class);
        $this->lines()->send($sender, $c, 'one too many');
    }

    public function test_an_existing_active_request_is_returned_not_duplicated_and_costs_nothing(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $offer = $this->offer($page);

        $first = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $offer);
        $remaining = app(KnockBudget::class)->remaining($sender);
        $second = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $offer);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($remaining, app(KnockBudget::class)->remaining($sender));
    }

    public function test_after_a_decline_the_same_ask_waits_for_the_cooldown(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $offer = $this->offer($page);

        $c = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $offer);
        $this->lines()->decline($this->responderOf($page), $c);

        try {
            $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $offer);
            $this->fail('expected cooldown');
        } catch (LineException $e) {
            $this->assertSame('exists', $e->reason);
        }

        $this->travel(31)->days();
        $again = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $offer);
        $this->assertNotSame($c->id, $again->id);
    }

    public function test_applications_must_start_from_a_live_role(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['is_employer' => true, 'contact_policy' => 'requests']);

        try {
            $this->lines()->knock($sender, ['entity' => $page->id], 'application', $this->card());
            $this->fail('no role, no application');
        } catch (LineException $e) {
            $this->assertSame('invalid', $e->reason);
        }

        $c = $this->lines()->knock($sender, ['entity' => $page->id], 'application', $this->card(['what' => 'Applying for the bookkeeper role']), $this->role($page));
        $this->assertSame('role', $c->context_type);
        $this->assertSame('application', $c->kind);
    }

    public function test_a_stranger_cannot_read_a_page_line_but_the_page_team_and_sender_can(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $c = $this->openLine($sender, $page);

        $inboxSender = $this->lines()->inbox($sender);
        $inboxOwner = $this->lines()->inbox($this->responderOf($page));
        $inboxStranger = $this->lines()->inbox($this->person());

        $this->assertCount(1, $inboxSender->items());
        $this->assertCount(1, $inboxOwner->items());
        $this->assertCount(0, $inboxStranger->items());
        $this->assertSame('initiator', $inboxSender->items()[0]['side']);
        $this->assertSame('recipient', $inboxOwner->items()[0]['side']);
        $this->assertSame($page->name, $inboxSender->items()[0]['counterpart']['name']);
    }

    public function test_inbox_tabs_and_unread(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);

        $c = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card());

        $this->assertCount(1, $this->lines()->inbox($owner, ['tab' => 'requests'])->items());
        $this->assertCount(1, $this->lines()->inbox($sender, ['tab' => 'sent'])->items());
        $this->assertCount(1, $this->lines()->inbox($owner, ['unread' => true, 'tab' => 'requests'])->items());

        $this->lines()->markRead($owner, $c);
        $this->assertCount(0, $this->lines()->inbox($owner, ['unread' => true, 'tab' => 'requests'])->items());

        $this->lines()->accept($owner, $c);
        $this->lines()->send($owner, $c->fresh(), 'Thursday works');

        $this->assertTrue($this->lines()->inbox($sender)->items()[0]['unread']);
        $this->lines()->markRead($sender, $c->fresh());
        $this->assertFalse($this->lines()->inbox($sender)->items()[0]['unread']);
    }

    public function test_new_messages_fold_into_one_unread_notification(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);
        $c = $this->openLine($sender, $page);

        $this->lines()->send($owner, $c, 'one');
        $this->lines()->send($owner, $c, 'two');
        $this->lines()->send($owner, $c, 'three');

        $messages = $sender->notifications()->where('data', 'like', '%line_message%')->get();
        $this->assertCount(1, $messages);
        $this->assertSame(3, $messages->first()->data['count']);
    }

    public function test_a_muted_line_sends_no_notifications(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);
        $c = $this->openLine($sender, $page);
        $this->lines()->muteConversation($sender, $c);
        $before = $sender->notifications()->count();

        $this->lines()->send($owner, $c, 'quiet');

        $this->assertSame($before, $sender->notifications()->count());
    }

    public function test_a_plain_dm_opens_only_when_the_policy_is_direct(): void
    {
        $sender = $this->person();
        $open = $this->policy($this->person(), 'anyone');
        $closed = $this->policy($this->person(), 'requests');

        $dm = $this->lines()->open($sender, $open);
        $this->assertSame('open', $dm->status);
        $this->assertSame($dm->id, $this->lines()->open($sender, $open)->id, 'idempotent');

        $this->expectException(LineException::class);
        $this->lines()->open($sender, $closed);
    }

    public function test_the_page_lines_are_shared_among_responders_and_read_marks_are_shared(): void
    {
        $sender = $this->person();
        $page = $this->page(null, ['contact_policy' => 'requests']);
        $owner = $this->responderOf($page);
        $second = $this->member($page, null, 'responder');
        $c = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card());

        $this->assertCount(1, $this->lines()->inbox($second, ['tab' => 'requests'])->items());
        $this->lines()->markRead($owner, $c);
        $this->assertCount(0, $this->lines()->inbox($second, ['unread' => true, 'tab' => 'requests'])->items());
    }
}
