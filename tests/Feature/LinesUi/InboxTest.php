<?php

namespace Tests\Feature\LinesUi;

use App\Livewire\Pulse\Lines\Inbox;
use App\Models\PulseEntityBlock;
use App\Services\Pulse\Lines\BlockService;
use App\Services\Pulse\Lines\LineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    private function lines(): LineService
    {
        return app(LineService::class);
    }

    /** A request waiting on the page's owner, from a stranger. */
    private function request(string $pageName = 'Moyo Plumbing'): array
    {
        $owner = $this->person();
        $page = $this->page($owner, ['name' => $pageName, 'contact_policy' => 'requests']);
        $offer = $this->offer($page);
        $sender = $this->person(['contact_policy' => 'requests'], ['name' => 'Sipho Ndlovu']);
        $line = $this->lines()->knock($sender, ['entity' => $page->id], 'enquiry', $this->card(), $offer);

        return [$owner, $sender, $page, $line];
    }

    public function test_the_page_renders_for_a_signed_in_person_and_needs_sign_in_otherwise(): void
    {
        $this->get('/lines')->assertRedirect('/login');

        $user = $this->person();
        $this->actingAs($user)->get('/lines')
            ->assertOk()
            ->assertSee('Lines')
            ->assertSee('How a line ends')
            ->assertSeeLivewire(Inbox::class);
    }

    public function test_the_alias_for_embedding_is_pulse_lines_inbox(): void
    {
        $this->actingAs($this->person());

        $html = Blade::render('<livewire:pulse.lines.inbox :entity-id="null" />');

        $this->assertStringContainsString('class="ln-inbox"', $html);
        $this->assertStringContainsString('No requests waiting.', $html);
    }

    public function test_requests_tab_shows_the_card_with_its_four_fields_and_the_honest_answers(): void
    {
        [$owner, , , $line] = $this->request();

        Livewire::actingAs($owner)->test(Inbox::class)
            ->assertSee('Fix a leaking geyser')
            ->assertSee('This Thursday')
            ->assertSee('Soweto')
            ->assertSee('R1000 to R3000')
            ->assertSee('Sipho Ndlovu')
            ->assertSee('Accept')
            ->assertSee('Not now')
            ->assertSee('Ignore')
            ->assertSee('Block')
            ->assertSee('Report')
            ->assertSee('Waiting for your answer');
    }

    public function test_accepting_opens_the_line_and_moves_it_to_open(): void
    {
        [$owner, , , $line] = $this->request();

        Livewire::actingAs($owner)->test(Inbox::class)
            ->call('accept', $line->id)
            ->assertDispatched('toast');

        $this->assertSame('open', $line->fresh()->status);

        Livewire::actingAs($owner)->test(Inbox::class, ['startTab' => 'open'])->assertSee('Geyser repair');
    }

    public function test_not_now_is_a_quiet_decline_and_the_sender_hears_only_the_chosen_short_reason(): void
    {
        [$owner, $sender, , $line] = $this->request();

        Livewire::actingAs($owner)->test(Inbox::class)
            ->call('chooseDecline', $line->id)
            ->assertSee('Pick a short reason')
            ->assertSee('Not a fit')
            ->call('decline')
            ->assertDispatched('toast');

        $fresh = $line->fresh();
        $this->assertSame('declined', $fresh->status);
        $this->assertSame('not_now', $fresh->decline_reason);

        // The sender reads exactly "Not now" and nothing about who or why.
        Livewire::actingAs($sender)->test(Inbox::class, ['startTab' => 'closed'])
            ->assertSee('Not now')
            ->assertDontSee('Needs your answer');
    }

    public function test_a_short_reason_other_than_the_default_is_what_the_sender_sees(): void
    {
        [$owner, $sender, , $line] = $this->request();

        Livewire::actingAs($owner)->test(Inbox::class)
            ->call('chooseDecline', $line->id)
            ->set('declineReason', 'no_capacity')
            ->call('decline');

        Livewire::actingAs($sender)->test(Inbox::class, ['startTab' => 'closed'])->assertSee('No capacity right now');
    }

    public function test_ignoring_sets_the_request_aside_quietly_and_it_still_shows_in_ignored(): void
    {
        [$owner, $sender, , $line] = $this->request();

        Livewire::actingAs($owner)->test(Inbox::class)->call('ignore', $line->id);

        Livewire::actingAs($owner)->test(Inbox::class, ['startTab' => 'requests'])->assertDontSee('Fix a leaking geyser');
        Livewire::actingAs($owner)->test(Inbox::class, ['startTab' => 'ignored'])
            ->assertSee('Fix a leaking geyser')
            ->assertSee('You set this aside quietly');

        // The sender sees nothing change: it is still waiting for them.
        Livewire::actingAs($sender)->test(Inbox::class, ['startTab' => 'sent'])->assertSee('Waiting for them');
    }

    public function test_a_stranger_cannot_answer_someone_elses_request(): void
    {
        [, , , $line] = $this->request();
        $stranger = $this->person();

        Livewire::actingAs($stranger)->test(Inbox::class)->call('accept', $line->id)->assertNotFound();
        $this->assertSame('requested', $line->fresh()->status);
    }

    public function test_the_sender_cannot_accept_their_own_request(): void
    {
        [, $sender, , $line] = $this->request();

        Livewire::actingAs($sender)->test(Inbox::class)->call('accept', $line->id)->assertForbidden();
        $this->assertSame('requested', $line->fresh()->status);
    }

    public function test_a_responder_of_the_page_answers_for_it_and_an_editor_does_not(): void
    {
        [$owner, , $page, $line] = $this->request();
        $responder = $this->member($page, null, 'responder');
        $editor = $this->member($page, null, 'editor');

        Livewire::actingAs($editor)->test(Inbox::class)->assertDontSee('Fix a leaking geyser');
        Livewire::actingAs($editor)->test(Inbox::class)->call('accept', $line->id)->assertNotFound();

        Livewire::actingAs($responder)->test(Inbox::class)->assertSee('Fix a leaking geyser')->call('accept', $line->id);
        $this->assertSame('open', $line->fresh()->status);
    }

    public function test_the_switcher_shows_one_pages_lines_only_and_names_it(): void
    {
        [$owner, , $page, $line] = $this->request('Moyo Plumbing');
        $second = $this->page($owner, ['name' => 'Moyo Electrical', 'contact_policy' => 'requests']);

        Livewire::actingAs($owner)->test(Inbox::class)
            ->assertSee('Showing lines for')
            ->assertSee('Moyo Plumbing')
            ->assertSee('Moyo Electrical')
            ->call('switchTo', $second->id)
            ->assertSet('entityId', $second->id)
            ->assertDontSee('Fix a leaking geyser')
            ->call('switchTo', $page->id)
            ->assertSee('Fix a leaking geyser');
    }

    public function test_just_me_shows_the_lines_that_are_mine_and_not_my_pages(): void
    {
        [$owner, , $page] = $this->request('Moyo Plumbing');
        $otherOwner = $this->person();
        $theirPage = $this->page($otherOwner, ['name' => 'Karoo Books', 'contact_policy' => 'requests']);
        $this->lines()->knock($owner, ['entity' => $theirPage->id], 'enquiry', $this->card(['what' => 'Need my books done']), $this->offer($theirPage));

        Livewire::actingAs($owner)->test(Inbox::class)
            ->assertSee('Just me')
            ->call('setTab', 'sent')->call('switchToMe')
            ->assertSet('meOnly', true)
            ->assertSee('Need my books done')
            ->call('setTab', 'requests')
            ->assertDontSee('Fix a leaking geyser')
            ->call('switchTo', $page->id)
            ->assertSet('meOnly', false)
            ->assertSee('Fix a leaking geyser');
    }

    public function test_the_switcher_refuses_a_page_the_person_does_not_answer_for(): void
    {
        [$owner] = $this->request();
        $other = $this->page(null, ['name' => 'Someone Else']);

        Livewire::actingAs($owner)->test(Inbox::class)->call('switchTo', $other->id)->assertSet('entityId', null);
    }

    public function test_embedding_with_an_entity_id_locks_the_list_to_that_page(): void
    {
        [$owner, , $page] = $this->request();
        $second = $this->page($owner, ['name' => 'Moyo Electrical', 'contact_policy' => 'requests']);

        Livewire::actingAs($owner)->test(Inbox::class, ['entityId' => $second->id])
            ->assertSet('entityId', $second->id)
            ->assertDontSee('Fix a leaking geyser');
    }

    public function test_no_switcher_for_a_person_who_answers_for_no_page(): void
    {
        Livewire::actingAs($this->person())->test(Inbox::class)->assertDontSee('Showing lines for');
    }

    public function test_every_tab_has_an_honest_empty_state(): void
    {
        $user = $this->person();
        $expect = [
            'requests' => 'No requests waiting.',
            'open' => 'No lines yet.',
            'sent' => 'Nothing waiting on anyone.',
            'closed' => 'Nothing closed yet.',
            'ignored' => 'Nothing set aside.',
        ];

        foreach ($expect as $tab => $text) {
            Livewire::actingAs($user)->test(Inbox::class, ['startTab' => $tab])->assertSee($text);
        }

        Livewire::actingAs($user)->test(Inbox::class, ['startTab' => 'open'])->assertSee('Knock on something on the Floor.');
    }

    public function test_unread_counts_show_on_the_tabs_and_clear_after_reading(): void
    {
        $owner = $this->policy($this->person(), 'anyone');
        $other = $this->person();
        $line = $this->openLine($other, $owner, 'dm');
        $this->lines()->send($other, $line, 'Are you still free on Thursday?');

        $component = Livewire::actingAs($owner)->test(Inbox::class, ['startTab' => 'open']);
        $component->assertSeeHtml('class="ln-count"')->assertSee('Are you still free on Thursday?');

        $this->lines()->markRead($owner, $line->fresh());

        Livewire::actingAs($owner)->test(Inbox::class, ['startTab' => 'open'])->assertDontSeeHtml('class="ln-count"');
    }

    public function test_the_unread_filter_leaves_out_lines_with_nothing_new(): void
    {
        $owner = $this->policy($this->person(), 'anyone');
        $a = $this->person();
        $b = $this->person();
        $one = $this->openLine($a, $owner, 'dm');
        $two = $this->openLine($b, $owner, 'dm');
        $this->lines()->send($a, $one, 'Hello there');
        $this->lines()->markRead($owner, $two->fresh());

        Livewire::actingAs($owner)->test(Inbox::class, ['startTab' => 'open'])
            ->set('unreadOnly', true)
            ->assertSee('Hello there')
            ->assertSee('Unread only');

        $names = Livewire::actingAs($owner)->test(Inbox::class, ['startTab' => 'open'])->set('unreadOnly', true)->viewData('lines');
        $this->assertCount(1, $names);
    }

    public function test_the_kind_filter_narrows_the_list_and_clearing_it_brings_everything_back(): void
    {
        $owner = $this->policy($this->person(), 'anyone');
        $this->openLine($this->person(), $owner, 'dm');

        Livewire::actingAs($owner)->test(Inbox::class, ['startTab' => 'open'])
            ->set('kind', 'quote')
            ->assertSee('Nothing matches that.')
            ->call('clearFilters')
            ->assertSet('kind', '')
            ->assertDontSee('Nothing matches that.');
    }

    public function test_expired_requests_read_closed_no_reply_to_the_sender(): void
    {
        [$owner, $sender, , $line] = $this->request();
        $line->forceFill(['created_at' => now()->subDays(15)])->save();
        $this->artisan('pulse:lines:sweep')->assertSuccessful();

        $this->assertSame('closed', $line->fresh()->status);
        Livewire::actingAs($sender)->test(Inbox::class, ['startTab' => 'closed'])->assertSee('Closed, no reply');
        Livewire::actingAs($owner)->test(Inbox::class, ['startTab' => 'closed'])->assertSee('Lapsed, no answer from you');
    }

    public function test_a_sent_request_shows_how_long_it_has_waited_and_can_be_withdrawn(): void
    {
        [, $sender, , $line] = $this->request();

        Livewire::actingAs($sender)->test(Inbox::class, ['startTab' => 'sent'])
            ->assertSee('Waiting for them')
            ->assertSee('Closes as no reply on')
            ->assertSee('Withdraw this request')
            ->call('closeLine', $line->id);

        $this->assertSame('closed', $line->fresh()->status);
        $this->assertSame('withdrawn', $line->fresh()->closed_reason);
    }

    public function test_blocking_from_the_inbox_closes_the_line_and_hides_both_ways(): void
    {
        [$owner, $sender, , $line] = $this->request();

        Livewire::actingAs($owner)->test(Inbox::class)
            ->call('askBlock', $line->id)
            ->assertSee('They are not told')
            ->call('confirmBlock')
            ->assertDispatched('toast');

        $this->assertTrue(BlockService::blocked($owner, $sender) || PulseEntityBlock::query()->where('user_id', $sender->id)->exists());
        $this->assertNotSame('requested', $line->fresh()->status);
    }

    public function test_lines_a_person_archived_are_left_out_of_the_tabs(): void
    {
        $owner = $this->policy($this->person(), 'anyone');
        $line = $this->openLine($this->person(), $owner, 'dm');
        DB::table('pulse_conversation_user')
            ->where(['pulse_conversation_id' => $line->id, 'user_id' => $owner->id])->update(['archived_at' => now()]);

        Livewire::actingAs($owner)->test(Inbox::class, ['startTab' => 'open'])->assertSee('No lines yet.');
    }

    public function test_unknown_tabs_and_kinds_fall_back_to_safe_values(): void
    {
        Livewire::actingAs($this->person())->test(Inbox::class, ['startTab' => 'nonsense'])->assertSet('tab', 'requests');
        Livewire::actingAs($this->person())->test(Inbox::class)->set('kind', 'zzz')->call('refresh')->assertSet('kind', '');
    }

    public function test_it_opens_on_the_first_tab_that_has_something_in_it(): void
    {
        // Nothing anywhere: the honest empty Requests tab.
        Livewire::actingAs($this->person())->test(Inbox::class)->assertSet('tab', 'requests');

        // A request waiting: Requests.
        [$owner, , , $line] = $this->request();
        Livewire::actingAs($owner)->test(Inbox::class)->assertSet('tab', 'requests');

        // Answered, nothing waiting: the lines are in Open, so that is where it opens (the badge counts them there).
        $this->lines()->accept($owner, $line);
        Livewire::actingAs($owner)->test(Inbox::class)->assertSet('tab', 'open')->assertSee('Geyser repair');

        // An explicit tab always wins.
        Livewire::actingAs($owner)->test(Inbox::class, ['startTab' => 'closed'])->assertSet('tab', 'closed');
    }

    public function test_the_sender_with_only_a_sent_request_lands_on_sent(): void
    {
        [, $sender] = $this->request();

        Livewire::actingAs($sender)->test(Inbox::class)->assertSet('tab', 'sent');
    }

    public function test_a_business_lines_tab_opens_on_its_open_lines_too(): void
    {
        [$owner, , $page, $line] = $this->request();
        $this->lines()->accept($owner, $line);

        Livewire::actingAs($owner)->test(Inbox::class, ['entityId' => $page->id])->assertSet('tab', 'open');
    }

    public function test_the_badge_link_names_the_tab_that_explains_the_count(): void
    {
        $js = file_get_contents(resource_path('js/pulse/shell.js'));

        $this->assertStringContainsString("tab=requests", $js);
        $this->assertStringContainsString("tab=open", $js);
    }
}
