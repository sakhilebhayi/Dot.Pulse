<?php

namespace Tests\Feature\Me;

use App\Livewire\Pulse\Me\Privacy;
use App\Models\PulseBlock;
use App\Models\PulseConversation;
use App\Models\PulseDoor;
use App\Models\PulseMessage;
use App\Models\PulseMute;
use App\Models\PulseNotificationPref;
use App\Services\Pulse\Lines\BlockService;
use App\Services\Pulse\Lines\Doors;
use Livewire\Livewire;

class PrivacyTest extends MeTestCase
{
    public function test_the_page_renders_for_a_newcomer_too_because_rights_are_not_gated(): void
    {
        $this->get('/me/privacy')->assertRedirect('/login');
        $this->actingAs($this->newcomer())->get('/me/privacy')->assertOk()->assertSee('Download your data')->assertSee('Erase what Pulse holds about you');
    }

    public function test_looking_at_the_ledger_never_switches_the_daily_summary_on(): void
    {
        $u = $this->member();

        Livewire::actingAs($u)->test(Privacy::class)->assertDontSee('emails you a summary');

        $pref = PulseNotificationPref::query()->where('user_id', $u->id)->firstOrFail();
        $this->assertFalse($pref->digest, 'opt-in: viewing the page is not consent');
    }

    public function test_the_ledger_lists_real_settings_and_one_tap_withdraws(): void
    {
        $u = $this->member(['discoverability' => 'anonymous', 'contact_policy' => 'anyone']);

        $c = Livewire::actingAs($u)->test(Privacy::class)
            ->assertSee('cannot see your name until you reveal it')
            ->assertSee('Anyone on Pulse can message you directly')
            ->assertSee('Stop employers finding me');

        $c->call('withdraw', 'discoverability.off')->assertDontSee('cannot see your name until you reveal it');
        $this->assertSame('off', $this->profileOf($u)->discoverability);

        $c->call('withdraw', 'contact_policy.requests');
        $this->assertSame('requests', $this->profileOf($u)->contact_policy);
    }

    public function test_withdrawing_something_that_is_not_a_ledger_action_is_refused(): void
    {
        $u = $this->member();

        $c = Livewire::actingAs($u)->test(Privacy::class)->call('withdraw', 'users.delete:1');
        $this->assertNotNull($c->get('problem'));
    }

    public function test_a_person_cannot_withdraw_a_door_or_line_that_is_not_theirs(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $door = app(Doors::class)->open($owner, 'Open for quotes', 'day');
        $doorRow = PulseDoor::query()->where('owner_id', $owner->id)->firstOrFail();

        $c = Livewire::actingAs($other)->test(Privacy::class)->call('withdraw', 'door.close:'.$doorRow->id);

        $this->assertNotNull($c->get('problem'));
        $this->assertSame('open', $doorRow->fresh()->status);
    }

    public function test_blocks_are_listed_and_can_be_lifted_only_by_the_blocker(): void
    {
        $me = $this->member();
        $them = $this->member();
        $page = $this->page();
        app(BlockService::class)->block($me, $them);
        app(BlockService::class)->block($me, $page);

        Livewire::actingAs($me)->test(Privacy::class)->assertSee($them->name)->assertSee($page->name);

        $stranger = $this->member();
        Livewire::actingAs($stranger)->test(Privacy::class)->call('unblockUser', $them->id)->call('unblockEntity', $page->id);
        $this->assertTrue(PulseBlock::query()->where('blocker_id', $me->id)->exists(), 'someone else cannot lift my block');

        $c = Livewire::actingAs($me)->test(Privacy::class);
        $c->call('unblockUser', $them->id)->call('unblockEntity', $page->id)->assertDontSee($them->name);
        $this->assertFalse(PulseBlock::query()->where('blocker_id', $me->id)->exists());
    }

    public function test_an_anonymous_person_is_never_named_in_the_block_or_mute_list(): void
    {
        $me = $this->member();
        $anon = $this->person(['discoverability' => 'anonymous']);
        app(BlockService::class)->block($me, $anon);
        PulseMute::query()->create(['user_id' => $me->id, 'kind' => 'user', 'target' => (string) $anon->id]);

        $html = Livewire::actingAs($me)->test(Privacy::class)->assertSee('An anonymous professional')->html();

        $this->assertStringNotContainsString($anon->name, $html);
        $this->assertStringNotContainsString($anon->email, $html);
    }

    public function test_mutes_are_listed_and_removed(): void
    {
        $me = $this->member();
        PulseMute::query()->create(['user_id' => $me->id, 'kind' => 'topic', 'target' => 'plumbing']);

        $c = Livewire::actingAs($me)->test(Privacy::class)->assertSee('Plumbing');
        $c->call('unmute', 'topic', 'plumbing')->assertDontSee('Plumbing');
        $this->assertSame(0, PulseMute::query()->where('user_id', $me->id)->count());
    }

    public function test_the_export_is_a_json_download_of_my_own_data_only(): void
    {
        $me = $this->member(['headline' => 'My own headline']);
        $other = $this->member(['headline' => 'Somebody elses headline']);
        $anon = $this->person(['discoverability' => 'anonymous']);

        $res = $this->actingAs($me)->get('/me/privacy/export');

        $res->assertOk()->assertHeader('content-type', 'application/json; charset=utf-8');
        $this->assertStringContainsString('attachment', $res->headers->get('content-disposition'));
        $body = $res->streamedContent();
        $data = json_decode($body, true);

        $this->assertSame($me->email, $data['account']['email']);
        $this->assertSame('My own headline', $data['profile']['headline']);
        $this->assertStringNotContainsString('Somebody elses headline', $body);
        $this->assertStringNotContainsString($other->email, $body);
        $this->assertStringNotContainsString($anon->email, $body);
        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'privacy.export', 'actor_id' => $me->id]);
    }

    public function test_the_export_leaves_out_the_other_side_of_a_line_that_was_anonymous_to_me_and_their_email(): void
    {
        $me = $this->member();
        $them = $this->member();
        $them->forceFill(['name' => 'Zodwa Hidden'])->save();
        $c = PulseConversation::query()->create([
            'kind' => 'dm', 'status' => 'open', 'initiator_id' => $them->id, 'recipient_id' => $me->id,
            'context_title' => 'A chat', 'first_response_at' => now(),
        ]);
        PulseMessage::query()->create(['pulse_conversation_id' => $c->id, 'user_id' => $them->id, 'kind' => 'text', 'body' => 'Hello there', 'side' => 'initiator']);

        $body = $this->actingAs($me)->get('/me/privacy/export')->streamedContent();

        $this->assertStringContainsString('Hello there', $body, 'what they said to me is mine to take');
        $this->assertStringNotContainsString($them->email, $body);
    }

    public function test_the_export_is_rate_limited(): void
    {
        $me = $this->member();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($me)->get('/me/privacy/export')->assertOk();
        }

        $this->actingAs($me)->get('/me/privacy/export')->assertStatus(429);
    }

    public function test_the_export_needs_sign_in(): void
    {
        $this->get('/me/privacy/export')->assertRedirect('/login');
    }

    public function test_erasing_needs_the_typed_phrase(): void
    {
        $me = $this->member(['headline' => 'Keep me']);

        Livewire::actingAs($me)->test(Privacy::class)
            ->call('startErase')->set('confirmation', 'erase')->call('erase')->assertHasErrors(['confirmation']);

        $this->assertSame('Keep me', $this->profileOf($me)->headline);
        $this->assertAuthenticated();
    }

    public function test_erasing_clears_pulse_data_signs_out_and_starts_the_person_fresh(): void
    {
        $me = $this->member(['headline' => 'Erase me', 'bio' => 'Private words', 'discoverability' => 'anonymous', 'skills' => json_encode(['plumbing'])]);
        $friend = $this->member();
        $line = PulseConversation::query()->create(['kind' => 'dm', 'status' => 'open', 'initiator_id' => $me->id, 'recipient_id' => $friend->id, 'context_title' => 'Chat', 'first_response_at' => now()]);
        PulseMessage::query()->create(['pulse_conversation_id' => $line->id, 'user_id' => $me->id, 'kind' => 'text', 'body' => 'a secret', 'side' => 'initiator']);
        PulseMute::query()->create(['user_id' => $me->id, 'kind' => 'topic', 'target' => 'solar']);

        $this->actingAs($me);
        Livewire::test(Privacy::class)
            ->call('startErase')->set('confirmation', '  Erase My Data ')->call('erase')->assertRedirect('/');

        $p = $this->profileOf($me);
        $this->assertNull($p->headline);
        $this->assertNull($p->bio);
        $this->assertSame('off', $p->discoverability);
        $this->assertNull($p->onboarded_at, 'they begin again if they return');
        $this->assertNull($p->age_confirmed_at);
        $this->assertSame(0, PulseMute::query()->where('user_id', $me->id)->count());
        $this->assertSame('This message was deleted.', PulseMessage::query()->where('user_id', $me->id)->value('body'));
        $this->assertGuest();
        $this->assertDatabaseHas('users', ['id' => $me->id]);
        $this->assertSame('Chat', PulseConversation::query()->find($line->id)->context_title, 'the other side keeps the thread');
    }

    public function test_the_only_owner_of_a_page_must_hand_it_over_first(): void
    {
        $me = $this->member(['headline' => 'Keep me']);
        $page = $this->page($me);

        $c = Livewire::actingAs($me)->test(Privacy::class)->call('startErase')->set('confirmation', 'erase my data')->call('erase');

        $this->assertStringContainsString($page->name, $c->get('problem'));
        $this->assertSame('Keep me', $this->profileOf($me)->headline);
        $this->assertAuthenticated();
    }

    public function test_the_erase_panel_says_what_is_kept_and_why(): void
    {
        Livewire::actingAs($this->member())->test(Privacy::class)
            ->assertSee('Kept, and why')->assertSee('Confirmed results')->assertSee('cannot come back by erasing');
    }
}
