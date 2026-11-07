<?php

namespace Tests\Feature\Discovery;

use App\Models\PulseMatch;
use App\Models\PulseMute;
use App\Models\PulseSave;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\Beat;
use App\Services\Pulse\Discovery\Floor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DiscoveryFixtures;
use Tests\TestCase;

class FloorTest extends TestCase
{
    use DiscoveryFixtures;
    use RefreshDatabase;

    private User $me;

    private object $geo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geo = $this->dGeo();
        $this->me = $this->dUser(['place' => $this->geo->jhb]);
    }

    private function floor(): Floor
    {
        return app(Floor::class);
    }

    /** A signal with a match for $this->me. */
    private function matched(array $attrs = [], float $score = 50.0): PulseSignal
    {
        $s = $this->dSignal(array_merge(['owner_id' => $this->dUser()->id, 'kind' => 'news', 'subject_type' => 'post'], $attrs));
        $this->dMatch($this->me, $s, $score);

        return $s;
    }

    private function ids(array $round): array
    {
        return array_map(fn (Beat $b) => $b->signal->id, $round['beats']);
    }

    public function test_a_round_is_at_most_twenty_beats_then_the_cursor_continues_and_the_last_page_is_caught_up(): void
    {
        for ($i = 0; $i < 45; $i++) {
            $this->matched(['title' => "Beat {$i}", 'kind' => ['news', 'ask', 'event'][$i % 3], 'entity_id' => null], 90 - $i);
        }

        $one = $this->floor()->round($this->me);
        $this->assertCount(20, $one['beats']);
        $this->assertFalse($one['caught_up']);
        $this->assertNull($one['next_round_at']);
        $this->assertSame(45, $one['counts']['new']);
        $this->assertNotNull($one['next']);

        $two = $this->floor()->round($this->me, 'all', $one['next']);
        $this->assertCount(20, $two['beats']);
        $this->assertEmpty(array_intersect($this->ids($one), $this->ids($two)), 'no beat twice');

        $three = $this->floor()->round($this->me, 'all', $two['next']);
        $this->assertCount(5, $three['beats']);
        $this->assertNull($three['next']);
        $this->assertTrue($three['caught_up']);
        $this->assertNotNull($three['next_round_at']);
        $this->assertTrue($three['next_round_at']->isFuture());
        $this->assertSame(0, $three['next_round_at']->minute % 10);
    }

    public function test_a_bad_cursor_starts_from_the_top(): void
    {
        $this->matched();

        $this->assertCount(1, $this->floor()->round($this->me, 'all', 'not-a-cursor')['beats']);
    }

    public function test_nothing_new_is_a_calm_caught_up_state(): void
    {
        $round = $this->floor()->round($this->me);

        $this->assertSame([], $round['beats']);
        $this->assertTrue($round['caught_up']);
        $this->assertNull($round['next']);
        $this->assertSame(0, $round['counts']['new']);
        $this->assertNotNull($round['next_round_at']);
    }

    public function test_best_matches_come_first(): void
    {
        $low = $this->matched([], 20);
        $high = $this->matched([], 80);
        $mid = $this->matched([], 50);

        $this->assertSame([$high->id, $mid->id, $low->id], $this->ids($this->floor()->round($this->me)));
    }

    public function test_never_more_than_three_in_a_row_of_one_kind_or_one_business(): void
    {
        $page = $this->dEntity($this->dUser(), ['name' => 'Loud Seller']);
        $other = $this->dEntity($this->dUser(), ['name' => 'Quiet Seller']);

        // Ten top-scoring offers, eight of them from one business, then lower-scoring needs and news.
        for ($i = 0; $i < 8; $i++) {
            $this->matched(['kind' => 'offer', 'subject_type' => 'offer', 'entity_id' => $page->id, 'owner_id' => $page->created_by], 95 - $i);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->matched(['kind' => 'offer', 'subject_type' => 'offer', 'entity_id' => $other->id, 'owner_id' => $other->created_by], 80 - $i);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->matched(['kind' => 'need', 'subject_type' => 'need'], 60 - $i);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->matched(['kind' => 'news'], 40 - $i);
        }

        $beats = $this->floor()->round($this->me)['beats'];
        $this->assertCount(20, $beats);

        $run = fn (callable $key) => collect($beats)->map($key)->reduce(function ($c, $k) {
            $c['max'] = max($c['max'], $k === $c['last'] && $k !== null ? ++$c['n'] : ($c['n'] = 1));
            $c['last'] = $k;

            return $c;
        }, ['last' => '__', 'n' => 0, 'max' => 0])['max'];

        $this->assertLessThanOrEqual(3, $run(fn (Beat $b) => $b->kind), 'kind run');
        $this->assertLessThanOrEqual(3, $run(fn (Beat $b) => $b->signal->entity_id), 'business run');
        $this->assertSame($beats[0]->kind, 'offer');
    }

    public function test_when_only_one_kind_is_left_the_rule_gives_way_instead_of_hiding_things(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->matched(['kind' => 'news'], 70 - $i);
        }

        $this->assertCount(6, $this->floor()->round($this->me)['beats']);
    }

    public function test_exclusions_hold_at_read_time_even_for_stored_matches(): void
    {
        $ok = $this->matched();
        $held = $this->matched(['status' => 'held']);
        $removed = $this->matched(['status' => 'removed']);
        $paused = $this->matched(['status' => 'paused']);
        $expired = $this->matched(['expires_at' => now()->subMinute()]);
        $own = $this->matched(['owner_id' => $this->me->id]);

        $blocked = $this->dUser();
        $blockedS = $this->matched(['owner_id' => $blocked->id]);
        $this->dBlock($this->me, $blocked);

        $blocker = $this->dUser();
        $blockerS = $this->matched(['owner_id' => $blocker->id]);
        $this->dBlock($blocker, $this->me);

        $mutedUser = $this->dUser();
        $mutedUserS = $this->matched(['owner_id' => $mutedUser->id]);
        $this->dMute($this->me, 'user', (string) $mutedUser->id);

        $mutedEntity = $this->dEntity($this->dUser());
        $mutedEntityS = $this->matched(['entity_id' => $mutedEntity->id, 'owner_id' => null]);
        $this->dMute($this->me, 'entity', $mutedEntity->slug);

        $this->dMute($this->me, 'topic', 'Welding');
        $mutedTopic = $this->matched(['topics' => ['welding']]);

        $dismissed = $this->matched();
        PulseMatch::where('signal_id', $dismissed->id)->update(['dismissed_at' => now()]);

        $saved = $this->matched();
        PulseSave::create(['user_id' => $this->me->id, 'saveable_type' => 'signal', 'saveable_id' => $saved->id]);

        $seen = $this->matched();
        PulseMatch::where('signal_id', $seen->id)->update(['seen_at' => now()]);

        $this->assertSame([$ok->id], $this->ids($this->floor()->round($this->me)));
    }

    public function test_talent_is_for_employers_only_and_never_names_the_person(): void
    {
        $pro = $this->dUser(['skills' => ['welding'], 'discoverability' => 'anonymous', 'experience_years' => 8, 'place' => $this->geo->sandton], ['name' => 'Zanele Dlamini', 'email' => 'zanele.secret@example.test']);
        $profileId = DB::table('pulse_profiles')->where('user_id', $pro->id)->value('id');
        $s = $this->matched(['kind' => 'talent', 'subject_type' => 'profile', 'subject_id' => $profileId, 'owner_id' => $pro->id, 'anonymous' => true, 'title' => 'Senior welder open to work', 'skills' => ['welding'], 'place' => $this->geo->sandton]);

        // Not an employer: nothing.
        $this->assertSame([], $this->ids($this->floor()->round($this->me)));

        $this->dEntity($this->me, ['is_employer' => true]);
        $round = $this->floor()->round($this->me);
        $this->assertSame([$s->id], $this->ids($round));

        $beat = $round['beats'][0];
        $dump = json_encode($beat->toArray()).serialize($beat->signal->toArray()).json_encode($beat->card);
        $this->assertStringNotContainsString('Zanele', $dump);
        $this->assertStringNotContainsString('zanele.secret', $dump);
        $this->assertNull($beat->signal->owner_id);
        $this->assertNull($beat->subject);
        $this->assertNull($beat->from['name']);
        $this->assertTrue($beat->from['anonymous']);
        $this->assertSame('Senior welder open to work', $beat->card['headline']);
        $this->assertSame('5 to 10 years', $beat->card['experience_band']);
        $this->assertSame('Gauteng', $beat->card['region']);
        $this->assertSame(['Welding'], $beat->card['skills']);
        $this->assertNull($beat->place['id'], 'region only, never the suburb');
    }

    public function test_modes_filter_the_round(): void
    {
        $offer = $this->matched(['kind' => 'offer', 'subject_type' => 'offer']);
        $need = $this->matched(['kind' => 'need', 'subject_type' => 'need']);
        $business = $this->matched(['kind' => 'business', 'subject_type' => 'entity']);
        $role = $this->matched(['kind' => 'role', 'subject_type' => 'role']);
        $ask = $this->matched(['kind' => 'ask']);
        $event = $this->matched(['kind' => 'event']);
        $news = $this->matched(['kind' => 'news']);
        $tradeCollab = $this->matched(['kind' => 'collab', 'subject_type' => 'need', 'topics' => ['plumbing']]);
        $workCollab = $this->matched(['kind' => 'collab', 'subject_type' => 'need', 'topics' => ['leadership']]);

        $this->assertEqualsCanonicalizing([$offer->id, $need->id, $business->id, $tradeCollab->id], $this->ids($this->floor()->round($this->me, 'trade')));
        $this->assertEqualsCanonicalizing([$role->id, $workCollab->id], $this->ids($this->floor()->round($this->me, 'work')));
        $this->assertEqualsCanonicalizing([$ask->id, $event->id, $news->id], $this->ids($this->floor()->round($this->me, 'learn')));
        $this->assertCount(9, $this->floor()->round($this->me, 'all')['beats']);
        $this->assertCount(9, $this->floor()->round($this->me, 'nonsense')['beats'], 'an unknown mode is all');
    }

    public function test_local_mode_keeps_only_things_in_your_city_or_region(): void
    {
        $city = $this->matched(['kind' => 'offer', 'subject_type' => 'offer'], 50);
        PulseMatch::where('signal_id', $city->id)->update(['reasons' => json_encode([['code' => 'place_city', 'text' => 'In your city.', 'weight' => 14]])]);
        $region = $this->matched(['kind' => 'need', 'subject_type' => 'need'], 40);
        PulseMatch::where('signal_id', $region->id)->update(['reasons' => json_encode([['code' => 'place_region', 'text' => 'In your region.', 'weight' => 9]])]);
        $elsewhere = $this->matched(['kind' => 'news'], 60);

        $this->assertEqualsCanonicalizing([$city->id, $region->id], $this->ids($this->floor()->round($this->me, 'local')));
    }

    public function test_beats_carry_everything_the_ui_needs(): void
    {
        $seller = $this->dUser();
        $entity = $this->dEntity($seller, ['name' => 'Karoo Bakery', 'verification_status' => 'claimed', 'registration_number' => '2020/123456/07']);
        $signal = $this->dOfferSignal($entity, ['baking'], ['place' => $this->geo->jhb, 'excerpt' => 'Fresh bread daily.']);
        $this->dMatch($this->me, $signal, 61, [['code' => 'need_offer', 'text' => 'You need baking, they offer baking.', 'weight' => 40, 'ref' => 'baking']]);

        /** @var Beat $b */
        $b = $this->floor()->round($this->me)['beats'][0];

        $this->assertSame('offer', $b->kind);
        $this->assertSame($signal->title, $b->title);
        $this->assertSame('Fresh bread daily.', $b->excerpt);
        $this->assertSame('Karoo Bakery', $b->from['name']);
        $this->assertSame($entity->id, $b->from['entity']['id']);
        $this->assertSame('Claims a registration number. Not checked.', $b->from['ladder']['label']);
        $this->assertSame('Johannesburg, Gauteng', $b->place['label']);
        $this->assertSame('You need baking, they offer baking.', $b->reasons[0]['text']);
        $this->assertSame(61.0, $b->score);
        $this->assertNotNull($b->published_at);
        $this->assertTrue($b->can_knock['allowed']);
        $this->assertFalse($b->saved);
        $this->assertInstanceOf(\App\Models\PulseOffer::class, $b->subject);
        $this->assertArrayHasKey('reasons', $b->toArray());
    }

    public function test_a_page_that_takes_no_contact_cannot_be_knocked(): void
    {
        $entity = $this->dEntity($this->dUser(), ['contact_policy' => 'nobody']);
        $signal = $this->dOfferSignal($entity, ['baking']);
        $this->dMatch($this->me, $signal);

        $b = $this->floor()->round($this->me)['beats'][0];

        $this->assertFalse($b->can_knock['allowed']);
        $this->assertNotEmpty($b->can_knock['why']);
    }

    public function test_marking_seen_takes_beats_out_of_the_next_round(): void
    {
        $a = $this->matched([], 70);
        $b = $this->matched([], 60);

        $this->floor()->markSeen($this->me, [$a->id]);

        $this->assertSame([$b->id], $this->ids($this->floor()->round($this->me)));
        $this->assertNotNull(PulseMatch::firstWhere('signal_id', $a->id)->seen_at);
    }

    public function test_dismiss_removes_a_beat_and_keeps_a_short_reason_only(): void
    {
        $s = $this->matched();

        $this->floor()->dismiss($this->me, $s, 'wrong_place');

        $this->assertSame([], $this->ids($this->floor()->round($this->me)));
        $this->assertSame('wrong_place', PulseMatch::firstWhere('signal_id', $s->id)->dismissed_reason);

        $t = $this->matched();
        $this->floor()->dismiss($this->me, $t, 'a free text reason that is not on the list');
        $this->assertNull(PulseMatch::firstWhere('signal_id', $t->id)->dismissed_reason);
        $this->assertNotNull(PulseMatch::firstWhere('signal_id', $t->id)->dismissed_at);
    }

    public function test_save_for_later_unsave_and_the_saved_list(): void
    {
        $first = $this->matched(['title' => 'First saved thing']);
        $second = $this->matched(['title' => 'Second saved thing']);
        $unrelated = $this->matched();

        $this->floor()->saveForLater($this->me, $first);
        $this->floor()->saveForLater($this->me, $second);
        $this->floor()->saveForLater($this->me, $second); // twice is once

        $this->assertSame(2, PulseSave::where('user_id', $this->me->id)->count());
        $this->assertNotContains($first->id, $this->ids($this->floor()->round($this->me)), 'saved leaves the Floor');

        $list = $this->floor()->savedList($this->me);
        $this->assertSame(['Second saved thing', 'First saved thing'], $list->map(fn (Beat $b) => $b->title)->all());
        $this->assertTrue($list->first()->saved);

        $this->floor()->unsave($this->me, $first);
        $this->assertSame(['Second saved thing'], $this->floor()->savedList($this->me)->map(fn (Beat $b) => $b->title)->all());
    }

    public function test_saved_things_that_became_unavailable_disappear_from_the_list(): void
    {
        $s = $this->matched();
        $this->floor()->saveForLater($this->me, $s);
        $this->assertCount(1, $this->floor()->savedList($this->me));

        $s->update(['status' => 'removed']);
        $this->assertCount(0, $this->floor()->savedList($this->me));

        $s->update(['status' => 'live']);
        $this->dBlock($this->me, User::find($s->owner_id));
        $this->assertCount(0, $this->floor()->savedList($this->me));
    }

    public function test_you_cannot_save_what_you_may_not_see(): void
    {
        $held = $this->dSignal(['status' => 'held', 'owner_id' => $this->dUser()->id]);

        $this->expectException(AuthorizationException::class);
        $this->floor()->saveForLater($this->me, $held);
    }

    public function test_removing_a_topic_reason_mutes_the_topic_and_the_match_goes(): void
    {
        $s = $this->matched(['topics' => ['plumbing']]);
        $m = PulseMatch::firstWhere('signal_id', $s->id);

        $this->floor()->removeReason($this->me, $m, 'topic');

        $this->assertTrue(PulseMute::where(['user_id' => $this->me->id, 'kind' => 'topic', 'target' => 'bookkeeping'])->exists());
        $this->assertNotNull($m->fresh()->dismissed_at);
        $this->assertSame([], $this->ids($this->floor()->round($this->me)));
    }

    public function test_removing_other_inputs_mutes_places_businesses_and_people_but_not_fresh(): void
    {
        $entity = $this->dEntity($this->dUser());
        $s = $this->dSignal(['entity_id' => $entity->id, 'topics' => []]);
        $m = $this->dMatch($this->me, $s, 40, [
            ['code' => 'follows', 'text' => 'You follow X.', 'weight' => 15, 'ref' => (string) $entity->id],
            ['code' => 'place_city', 'text' => 'In your city.', 'weight' => 14, 'ref' => (string) $this->geo->jhb->id],
            ['code' => 'remote', 'text' => 'Remote.', 'weight' => 6, 'ref' => null],
            ['code' => 'fresh', 'text' => 'Posted today.', 'weight' => 5, 'ref' => null],
        ]);

        $this->floor()->removeReason($this->me, $m, 'place_city');
        $this->assertTrue(PulseMute::where(['kind' => 'place', 'target' => (string) $this->geo->jhb->id])->exists());

        $this->floor()->removeReason($this->me, $m->fresh(), 'remote');
        $this->assertTrue(PulseMute::where(['kind' => 'input', 'target' => 'remote'])->exists());

        try {
            $this->floor()->removeReason($this->me, $m->fresh(), 'fresh');
            $this->fail('fresh is not an input');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->floor()->removeReason($this->me, $m->fresh(), 'follows');
        $this->assertTrue(PulseMute::where(['kind' => 'entity', 'target' => (string) $entity->id])->exists());
    }

    public function test_you_cannot_edit_someone_elses_match(): void
    {
        $other = $this->dUser();
        $s = $this->dSignal(['owner_id' => $this->dUser()->id]);
        $m = $this->dMatch($other, $s);

        $this->expectException(AuthorizationException::class);
        $this->floor()->removeReason($this->me, $m, 'topic');
    }

    public function test_the_round_costs_a_fixed_number_of_queries_however_many_beats(): void
    {
        $count = function (int $n) {
            DB::table('pulse_matches')->delete();
            PulseSignal::query()->delete();

            for ($i = 0; $i < $n; $i++) {
                $seller = $this->dUser();
                $entity = $this->dEntity($seller);
                $s = $this->dOfferSignal($entity, ['plumbing'], ['place' => $this->geo->jhb]);
                $this->dMatch($this->me, $s, 90 - $i);
                $post = $this->dSignal(['kind' => 'news', 'subject_type' => 'post', 'owner_id' => $this->dUser()->id]);
                $this->dMatch($this->me, $post, 40 - $i / 10);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->floor()->round($this->me);
            $q = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $q;
        };

        $small = $count(3);
        $large = $count(20);

        $this->assertSame($small, $large, 'query count must not depend on the number of beats');
        $this->assertLessThanOrEqual(25, $large);
    }
}
