<?php

namespace Tests\Feature\Floor;

use App\Livewire\Pulse\Floor\Round;
use App\Models\PulseDoor;
use App\Models\PulseMatch;
use App\Models\PulseMute;
use App\Models\PulseSave;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

class RoundTest extends FloorTestCase
{
    private function round(string $mode = 'all', ?User $as = null)
    {
        return Livewire::actingAs($as ?? $this->me)->test(Round::class, ['mode' => $mode]);
    }

    public function test_the_round_shows_the_beats_that_match_with_their_reasons(): void
    {
        [$signal] = $this->offerFor('Bookkeeping for small shops');

        $this->round()
            ->assertSee('Bookkeeping for small shops')
            ->assertSee('You need bookkeeping, they offer bookkeeping')
            ->assertSee('Both in Johannesburg')
            ->assertSee('Monthly books and VAT returns.')
            ->assertSee('data-beat="'.$signal->id.'"', false)
            ->assertSee('Stop using this');
    }

    public function test_a_round_ends_with_the_caught_up_state_and_the_time_of_the_next_round(): void
    {
        $this->matched(['title' => 'Only beat']);

        $this->round()
            ->assertSee('Only beat')
            ->assertSee("That's the round. You are caught up.", false)
            ->assertSee('The next round is ready at')
            ->assertDontSee('Show the next batch')
            ->assertSet('caughtUp', true)
            ->assertSet('hasMore', false);
    }

    public function test_a_long_round_shows_twenty_then_a_batch_button_then_the_rest_and_the_end(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->matched(['title' => "Beat {$i}", 'kind' => ['news', 'ask', 'event'][$i % 3]], 90 - $i);
        }

        $c = $this->round();
        $this->assertCount(20, $c->get('beats'));
        $c->assertSee('Show the next batch')->assertSee('5 more in this round.')->assertDontSee("That's the round", false);

        $firstIds = array_column($c->get('beats'), 'signal_id');

        $c->call('showNext');
        $this->assertCount(25, $c->get('beats'));
        $this->assertCount(25, array_unique(array_column($c->get('beats'), 'signal_id')), 'no beat twice');
        $c->assertSee("That's the round. You are caught up.", false)->assertDontSee('Show the next batch');

        // The twenty on the first page were marked seen when the next batch was asked for.
        $this->assertSame(20, PulseMatch::where('user_id', $this->me->id)->whereIn('signal_id', $firstIds)->whereNotNull('seen_at')->count());
    }

    public function test_mark_seen_marks_only_beats_the_round_holds(): void
    {
        $shown = $this->matched(['title' => 'On screen']);
        $c = $this->round();
        $elsewhere = $this->matched(['title' => 'Arrived later']);

        $c->call('markSeen', [$shown->id, $elsewhere->id]);

        $this->assertNotNull(PulseMatch::where('signal_id', $shown->id)->value('seen_at'));
        $this->assertNull(PulseMatch::where('signal_id', $elsewhere->id)->value('seen_at'), 'a beat that is not on screen is never marked seen');
    }

    public function test_a_seen_beat_leaves_the_next_round_and_the_person_is_caught_up_not_empty(): void
    {
        $s = $this->matched(['title' => 'Seen already']);
        $this->round()->call('markSeen', [$s->id]);

        $this->round()
            ->assertDontSee('Seen already')
            ->assertSee("That's the round. You are caught up.", false)
            ->assertDontSee('Nothing matches yet.');
    }

    public function test_with_no_matches_at_all_the_empty_state_is_honest_and_actionable(): void
    {
        $this->round()
            ->assertSee('Nothing matches yet.')
            ->assertSee('Say what you need')
            ->assertSee('Say what you are open to')
            ->assertDontSee("That's the round", false);

        $this->round('local')->assertSee('Nothing near you yet.')->assertSee('Set your place');
    }

    public function test_a_mode_shows_only_its_own_kinds(): void
    {
        [$offer] = $this->offerFor('A trade thing');
        $this->matched(['title' => 'A learning thing', 'kind' => 'ask']);

        $this->round('trade')->assertSee('A trade thing')->assertDontSee('A learning thing');
        $this->round('learn')->assertSee('A learning thing')->assertDontSee('A trade thing');
        $this->round('all')->assertSee('A trade thing')->assertSee('A learning thing');
        $this->round('nonsense')->assertSet('mode', 'all');
    }

    public function test_not_for_me_removes_the_beat_and_keeps_the_reason(): void
    {
        $s = $this->matched(['title' => 'Not my thing']);
        $keep = $this->matched(['title' => 'Keep me']);

        $c = $this->round()->call('dismiss', $s->id, 'wrong_place');

        $c->assertDontSee('Not my thing')->assertSee('Keep me');
        $row = PulseMatch::where('signal_id', $s->id)->first();
        $this->assertNotNull($row->dismissed_at);
        $this->assertSame('wrong_place', $row->dismissed_reason);

        $this->round()->assertDontSee('Not my thing');
    }

    public function test_dismissing_with_an_unknown_reason_still_removes_it_without_storing_the_reason(): void
    {
        $s = $this->matched();
        $this->round()->call('dismiss', $s->id, 'because i said so');

        $row = PulseMatch::where('signal_id', $s->id)->first();
        $this->assertNotNull($row->dismissed_at);
        $this->assertNull($row->dismissed_reason);
    }

    public function test_save_and_unsave_change_the_beat_and_the_saved_list(): void
    {
        $s = $this->matched(['title' => 'Worth keeping']);

        $c = $this->round()->call('save', $s->id);
        $this->assertTrue($c->get('beats')[0]['saved']);
        $c->assertSee('Saved')->assertDispatched('pulse-saved-changed');
        $this->assertDatabaseHas('pulse_saves', ['user_id' => $this->me->id, 'saveable_id' => $s->id, 'saveable_type' => 'signal']);

        $c->call('unsave', $s->id);
        $this->assertFalse($c->get('beats')[0]['saved']);
        $this->assertSame(0, PulseSave::count());
    }

    public function test_a_beat_that_is_not_on_screen_cannot_be_saved_or_dismissed_through_the_round(): void
    {
        $this->matched(['title' => 'On screen']);
        $other = $this->dSignal(['owner_id' => $this->person()->id, 'kind' => 'news', 'subject_type' => 'post']);

        $this->round()->call('save', $other->id)->call('dismiss', $other->id);

        $this->assertSame(0, PulseSave::count());
        $this->assertNull(DB::table('pulse_matches')->where('signal_id', $other->id)->value('dismissed_at'));
    }

    public function test_removing_a_reason_mutes_that_input_and_the_beat_redraws_with_what_is_left(): void
    {
        $owner = $this->person();
        $entity = $this->dEntity($owner, ['name' => 'Sample Books']);
        $signal = $this->dOfferSignal($entity, ['bookkeeping'], ['title' => 'Books done right', 'place' => $this->geo->jhb]);
        $match = $this->dMatch($this->me, $signal, 60, [
            ['code' => 'topic', 'text' => 'They are into cycling, which you follow.', 'weight' => 24, 'ref' => 'cycling'],
            ['code' => 'place_city', 'text' => 'Both in Johannesburg', 'weight' => 14, 'ref' => (string) $this->geo->jhb->id],
        ]);

        $c = $this->round()->assertSee('They are into cycling, which you follow.');

        $c->call('removeReason', $match->id, 'topic');

        $this->assertDatabaseHas('pulse_mutes', ['user_id' => $this->me->id, 'kind' => 'topic', 'target' => 'cycling']);
        $c->assertDispatched('toast', message: 'Removed. We will stop using that.', kind: 'good');
        $c->assertSee('Books done right')->assertDontSee('They are into cycling, which you follow.');

        $texts = collect($c->get('beats')[0]['reasons'])->pluck('code')->all();
        $this->assertNotContains('topic', $texts);
        $this->assertContains('place_city', $texts, 'the reasons that are left are re-judged, not blanked');
    }

    public function test_removing_a_reason_redraws_every_beat_on_screen_that_used_the_same_input(): void
    {
        $reasons = fn () => [
            ['code' => 'topic', 'text' => 'They are into cycling, which you follow.', 'weight' => 24, 'ref' => 'cycling'],
            ['code' => 'place_city', 'text' => 'Both in Johannesburg', 'weight' => 14, 'ref' => (string) $this->geo->jhb->id],
        ];
        [, , $first] = $this->offerFor('First beat', $reasons());
        [, , $second] = $this->offerFor('Second beat', $reasons());

        $c = $this->round()->assertSee('First beat')->assertSee('Second beat');
        $this->assertGreaterThanOrEqual(2, substr_count($c->html(), 'They are into cycling, which you follow.'));

        $c->call('removeReason', $first->id, 'topic');

        // Both were shown (so both are "seen"); the one that was not tapped must not keep drawing the removed reason.
        $this->assertStringNotContainsString('They are into cycling, which you follow.', $c->html());
        $c->assertSee('First beat')->assertSee('Second beat');
        $this->assertNotContains('topic', array_column((array) $second->fresh()->reasons, 'code'));
    }

    public function test_removing_the_only_reason_takes_the_beat_off_the_floor(): void
    {
        $s = $this->matched(['title' => 'Only reason', 'kind' => 'ask', 'topics' => ['cycling'], 'subject_type' => 'post'], 40, [
            ['code' => 'topic', 'text' => 'You follow cycling.', 'weight' => 24, 'ref' => 'cycling'],
        ]);
        $match = PulseMatch::where('signal_id', $s->id)->first();

        $this->round()->call('removeReason', $match->id, 'topic')->assertDontSee('Only reason')->assertDispatched('toast');

        $this->assertNotNull($match->fresh()->dismissed_at);
        $this->assertSame(1, PulseMute::where('user_id', $this->me->id)->count());
    }

    public function test_a_reason_that_is_not_an_input_cannot_be_removed(): void
    {
        $s = $this->matched(['title' => 'Fresh one'], 30, [['code' => 'fresh', 'text' => 'Posted today.', 'weight' => 5, 'ref' => null]]);
        $match = PulseMatch::where('signal_id', $s->id)->first();

        $this->round()->call('removeReason', $match->id, 'fresh')->assertSee('Fresh one')->assertDispatched('toast', message: 'That one is not something you set, so it stays.', kind: 'bad');

        $this->assertSame(0, PulseMute::count());
    }

    public function test_nobody_can_remove_a_reason_from_somebody_elses_match(): void
    {
        $other = $this->person();
        $s = $this->matched(['title' => 'Theirs'], 40, [['code' => 'topic', 'text' => 'You follow cycling.', 'weight' => 24, 'ref' => 'cycling']], $other);
        $match = PulseMatch::where('signal_id', $s->id)->first();

        $this->round()->call('removeReason', $match->id, 'topic');

        $this->assertNull($match->fresh()->dismissed_at);
        $this->assertSame(0, PulseMute::count());
    }

    public function test_the_meter_offers_stop_using_this_only_on_reasons_a_person_controls(): void
    {
        $this->matched(['title' => 'Mixed'], 50, [
            ['code' => 'topic', 'text' => 'You follow cycling.', 'weight' => 24, 'ref' => 'cycling'],
            ['code' => 'fresh', 'text' => 'Posted today.', 'weight' => 5, 'ref' => null],
            ['code' => 'responsive', 'text' => 'They answer quickly.', 'weight' => 5, 'ref' => null],
        ]);

        $html = $this->round()->html();

        $this->assertSame(1, substr_count($html, "('topic')"));
        $this->assertStringNotContainsString("('fresh')", $html);
        $this->assertStringNotContainsString("('responsive')", $html);
        $this->assertStringContainsString('Posted today.', $html);
    }

    public function test_people_and_businesses_a_person_blocked_or_muted_never_show(): void
    {
        $blocked = $this->person();
        $this->matched(['title' => 'From a blocked person', 'owner_id' => $blocked->id]);
        $this->dBlock($this->me, $blocked);

        $mutedPerson = $this->person();
        $this->matched(['title' => 'From a muted person', 'owner_id' => $mutedPerson->id]);
        $this->dMute($this->me, 'user', (string) $mutedPerson->id);

        $this->matched(['title' => 'About muted cycling', 'topics' => ['cycling']]);
        $this->dMute($this->me, 'topic', 'cycling');

        [, $entity] = $this->offerFor('From a muted business');
        $this->dMute($this->me, 'entity', (string) $entity->id);

        $reverse = $this->person();
        $this->matched(['title' => 'From someone who blocked me', 'owner_id' => $reverse->id]);
        $this->dBlock($reverse, $this->me);

        $this->matched(['title' => 'A fine beat']);

        $this->round()
            ->assertSee('A fine beat')
            ->assertDontSee('From a blocked person')
            ->assertDontSee('From a muted person')
            ->assertDontSee('About muted cycling')
            ->assertDontSee('From a muted business')
            ->assertDontSee('From someone who blocked me');
    }

    public function test_held_removed_and_expired_signals_never_show(): void
    {
        $this->matched(['title' => 'Held one', 'status' => 'held']);
        $this->matched(['title' => 'Removed one', 'status' => 'removed']);
        $this->matched(['title' => 'Expired one', 'expires_at' => now()->subMinute()]);
        $this->matched(['title' => 'Live one']);

        $this->round()->assertSee('Live one')->assertDontSee('Held one')->assertDontSee('Removed one')->assertDontSee('Expired one');
    }

    public function test_an_anonymous_professional_is_the_anonymous_card_and_nothing_that_names_them(): void
    {
        $this->dEntity($this->me, ['name' => 'My Employer Page', 'is_employer' => true]);

        $pro = $this->person(['discoverability' => 'anonymous', 'skills' => ['payroll'], 'experience_years' => 7, 'place' => $this->geo->jhb], ['name' => 'Naledi Secretname', 'email' => 'naledi.secret@example.test']);
        $profileId = DB::table('pulse_profiles')->where('user_id', $pro->id)->value('id');
        $signal = $this->dSignal([
            'kind' => 'talent', 'subject_type' => 'profile', 'subject_id' => $profileId, 'owner_id' => $pro->id, 'anonymous' => true,
            'title' => 'Payroll specialist', 'skills' => ['payroll'], 'topics' => ['payroll'], 'place' => $this->geo->jhb,
        ]);
        $this->dMatch($this->me, $signal, 55, [['code' => 'role_talent', 'text' => 'They have skills you are hiring for: payroll.', 'weight' => 38, 'ref' => 'payroll']]);

        $c = $this->round('work')
            ->assertSee('Anonymous professional')
            ->assertSee('Payroll specialist')
            ->assertSee('Payroll')
            ->assertSee('Invite')
            ->assertDontSee('Naledi')
            ->assertDontSee('Secretname')
            ->assertDontSee('naledi.secret');

        $beat = $c->get('beats')[0];
        $this->assertTrue($beat['anonymous']);
        $this->assertNull($beat['from']['name']);
        $this->assertNull($beat['from']['avatar']);
        $this->assertNull($beat['from']['entity']);
        $this->assertSame('invite', $beat['knock']['kind']);
        $this->assertSame(['signal' => $signal->id], array_intersect_key($beat['knock']['target'], ['signal' => 1]));
        $this->assertSame(['signal', 'as_entity'], array_keys($beat['knock']['target']), 'the target names the signal and the inviting page, never the person');
        $this->assertStringNotContainsString('Naledi', json_encode($beat));
        $this->assertStringNotContainsString('naledi.secret', json_encode($beat));
    }

    public function test_a_person_without_an_employer_page_cannot_find_talent_at_all(): void
    {
        $pro = $this->person(['discoverability' => 'anonymous', 'skills' => ['payroll']]);
        $profileId = DB::table('pulse_profiles')->where('user_id', $pro->id)->value('id');
        $signal = $this->dSignal(['kind' => 'talent', 'subject_type' => 'profile', 'subject_id' => $profileId, 'owner_id' => $pro->id, 'anonymous' => true, 'title' => 'Hidden talent']);
        $this->dMatch($this->me, $signal);

        $this->round('work')->assertDontSee('Hidden talent');
    }

    public function test_a_door_beat_shows_when_it_closes_and_has_a_knock(): void
    {
        $owner = $this->person();
        $door = PulseDoor::create([
            'owner_id' => $owner->id, 'opened_by' => $owner->id, 'purpose' => 'Looking for a baker for Saturday', 'length' => 'week', 'status' => 'open',
            'opens_at' => now(), 'closes_at' => now()->addDays(3), 'knock_cap' => 10,
        ]);
        $signal = $this->dSignal([
            'kind' => 'door', 'subject_type' => $door->getMorphClass(), 'subject_id' => $door->id, 'owner_id' => $owner->id,
            'title' => 'Looking for a baker for Saturday', 'expires_at' => $door->closes_at,
        ]);
        $this->dMatch($this->me, $signal, 30, [['code' => 'place_city', 'text' => 'Both in Johannesburg', 'weight' => 14, 'ref' => '1']]);

        $c = $this->round()->assertSee('Looking for a baker for Saturday')->assertSee('Open until')->assertSee('Door');
        $knock = $c->get('beats')[0]['knock'];

        $this->assertSame(['door' => $door->id], $knock['target']);
        $this->assertSame('intro', $knock['kind']);
        $this->assertSame('door', $knock['contextType']);
    }

    public function test_a_beat_that_cannot_be_knocked_says_why_and_has_no_knock_button(): void
    {
        [, $entity] = $this->offerFor('Closed page offer');
        $entity->forceFill(['contact_policy' => 'nobody'])->save();

        $this->round()
            ->assertSee('Closed page offer')
            ->assertSee('This page is not taking new contact.')
            ->assertDontSee('data-act="knock"', false);
    }

    public function test_the_knock_button_hands_the_sheet_the_offer_and_its_page(): void
    {
        [$signal, $entity] = $this->offerFor();

        $knock = $this->round()->get('beats')[0]['knock'];

        $this->assertSame(['entity' => $entity->id], $knock['target']);
        $this->assertSame('enquiry', $knock['kind']);
        $this->assertSame('offer', $knock['contextType']);
        $this->assertSame((int) $signal->subject_id, $knock['contextId']);
    }

    public function test_the_beats_on_screen_cannot_be_edited_by_the_browser(): void
    {
        $this->matched();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->round()->set('beats', []);
    }

    public function test_a_page_with_a_full_round_asks_the_database_a_bounded_number_of_questions(): void
    {
        $this->actingAs($this->me);

        for ($i = 0; $i < 20; $i++) {
            [$signal] = $this->offerFor("Offer {$i}");
        }
        for ($i = 0; $i < 5; $i++) {
            $this->matched(['kind' => 'ask', 'title' => "Ask {$i}"]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('floor'))->assertOk();
        $big = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(80, $big, "a full Floor page ran {$big} queries");

        // Fewer beats, about the same number of queries: the count does not grow with the round.
        $small = $this->person();
        $this->offerFor('One', null, $small);
        $this->actingAs($small);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('floor'))->assertOk();
        $few = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($few + 12, $big, "{$big} queries for 25 beats against {$few} for one");
    }
}
