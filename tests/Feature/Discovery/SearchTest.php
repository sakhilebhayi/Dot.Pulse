<?php

namespace Tests\Feature\Discovery;

use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DiscoveryFixtures;
use Tests\TestCase;

class SearchTest extends TestCase
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

    private function search(string $q, array $filters = [], ?string $cursor = null): array
    {
        return app(SearchService::class)->search($this->me, $q, $filters, $cursor);
    }

    private function titles(array $res): array
    {
        return array_map(fn ($r) => $r['type'] === 'signal' ? $r['beat']->title : $r['entity']['name'], $res['results']);
    }

    public function test_title_beats_description_and_every_result_says_why(): void
    {
        $this->dSignal(['title' => 'Plumbing repairs and geysers', 'excerpt' => 'Fast and tidy.', 'topics' => ['plumbing'], 'owner_id' => $this->dUser()->id]);
        $this->dSignal(['title' => 'Handyman', 'excerpt' => 'We also do plumbing jobs.', 'owner_id' => $this->dUser()->id]);
        $this->dSignal(['title' => 'Bakery', 'excerpt' => 'Bread.', 'owner_id' => $this->dUser()->id]);

        $res = $this->search('plumbing');

        $this->assertSame(['Plumbing repairs and geysers', 'Handyman'], $this->titles($res));
        $this->assertContains('Matched your words in the title.', $res['results'][0]['why']);
        $this->assertContains('Matches the topic plumbing.', $res['results'][0]['why']);
        $this->assertContains('Matched your words in the description.', $res['results'][1]['why']);
        $this->assertGreaterThan($res['results'][1]['score'], $res['results'][0]['score']);
    }

    public function test_a_plumber_finds_plumbing_through_the_taxonomy(): void
    {
        $this->dSignal(['title' => 'Geyser repair', 'topics' => ['plumbing'], 'owner_id' => $this->dUser()->id]);

        $this->assertSame(['Geyser repair'], $this->titles($this->search('plumber')));
    }

    public function test_businesses_are_found_by_name(): void
    {
        $this->dEntity($this->dUser(), ['name' => 'Karoo Bakery', 'tagline' => 'Bread and rusks', 'industry' => 'Food']);
        $this->dEntity($this->dUser(), ['name' => 'Draft Bakery', 'status' => 'draft']);
        $this->dEntity($this->dUser(), ['name' => 'Suspended Bakery', 'status' => 'suspended']);

        $res = $this->search('bakery');

        $this->assertSame(['Karoo Bakery'], $this->titles($res));
        $this->assertSame('entity', $res['results'][0]['type']);
        $this->assertContains('Matched the business name.', $res['results'][0]['why']);
        $this->assertSame('Claims', substr((string) ($res['results'][0]['entity']['verification'] ?? 'Claims'), 0, 6));
    }

    public function test_a_business_with_its_own_signal_is_one_result_not_two(): void
    {
        $owner = $this->dUser();
        $entity = $this->dEntity($owner, ['name' => 'Karoo Bakery', 'tagline' => 'Bread and rusks']);
        $this->dSignal(['kind' => 'business', 'subject_type' => 'entity', 'subject_id' => $entity->id, 'entity_id' => $entity->id, 'owner_id' => $owner->id, 'title' => 'Karoo Bakery', 'topics' => ['baking']]);

        $res = $this->search('bakery');

        $this->assertSame(['Karoo Bakery'], $this->titles($res));
        $this->assertSame('signal', $res['results'][0]['type']);
    }

    public function test_a_matched_only_need_is_found_only_by_the_people_it_was_matched_to(): void
    {
        $owner = $this->dUser();
        $need = $this->dNeed($owner, ['title' => 'Welding for a gate', 'visibility' => 'matched']);
        $signal = $this->dSignal(['kind' => 'need', 'subject_type' => 'need', 'subject_id' => $need->id, 'owner_id' => $owner->id, 'title' => $need->title, 'topics' => ['welding']]);
        $open = $this->dNeed($owner, ['title' => 'Welding for a fence', 'visibility' => 'open']);
        $this->dSignal(['kind' => 'need', 'subject_type' => 'need', 'subject_id' => $open->id, 'owner_id' => $owner->id, 'title' => $open->title, 'topics' => ['welding']]);

        $this->assertSame(['Welding for a fence'], $this->titles($this->search('welding')));

        $this->dMatch($this->me, $signal, 50);
        $this->assertEqualsCanonicalizing(['Welding for a fence', 'Welding for a gate'], $this->titles($this->search('welding')));
    }

    public function test_not_for_me_on_a_search_result_sticks_even_when_there_was_no_match_row(): void
    {
        $owner = $this->dUser();
        $keep = $this->dSignal(['title' => 'Tiling for a bathroom', 'owner_id' => $owner->id]);
        $drop = $this->dSignal(['title' => 'Tiling for a patio', 'owner_id' => $owner->id]);

        $this->assertEqualsCanonicalizing(['Tiling for a bathroom', 'Tiling for a patio'], $this->titles($this->search('tiling')));

        app(\App\Services\Pulse\Discovery\Floor::class)->dismiss($this->me, $drop, 'not_interested');

        $this->assertDatabaseHas('pulse_matches', ['user_id' => $this->me->id, 'signal_id' => $drop->id]);
        $this->assertNotNull(DB::table('pulse_matches')->where('user_id', $this->me->id)->where('signal_id', $drop->id)->value('dismissed_at'));
        $this->assertSame(['Tiling for a bathroom'], $this->titles($this->search('tiling')));

        // A matching run never reopens a dismissed row.
        app(\App\Services\Pulse\Discovery\Matcher::class)->forUser($this->me);
        $this->assertNotNull(DB::table('pulse_matches')->where('user_id', $this->me->id)->where('signal_id', $drop->id)->value('dismissed_at'));
        $this->assertNotNull($keep->id);
    }

    public function test_held_removed_expired_and_paused_never_appear(): void
    {
        $owner = $this->dUser();
        $this->dSignal(['title' => 'Welding live', 'owner_id' => $owner->id]);
        foreach (['held', 'removed', 'paused', 'expired'] as $status) {
            $this->dSignal(['title' => "Welding {$status}", 'status' => $status, 'owner_id' => $owner->id]);
        }
        $this->dSignal(['title' => 'Welding lapsed', 'expires_at' => now()->subMinute(), 'owner_id' => $owner->id]);

        $this->assertSame(['Welding live'], $this->titles($this->search('welding')));
    }

    public function test_blocked_people_and_hidden_businesses_never_appear(): void
    {
        $blocked = $this->dUser();
        $this->dBlock($this->me, $blocked);
        $this->dSignal(['title' => 'Tiling from blocked', 'owner_id' => $blocked->id]);

        $blocker = $this->dUser();
        $this->dBlock($blocker, $this->me);
        $this->dSignal(['title' => 'Tiling from blocker', 'owner_id' => $blocker->id]);
        $this->dEntity($blocker, ['name' => 'Tiling Kings']);

        $mutedEntity = $this->dEntity($this->dUser(), ['name' => 'Tiling Muted']);
        $this->dMute($this->me, 'entity', (string) $mutedEntity->id);

        $ok = $this->dSignal(['title' => 'Tiling from a friend', 'owner_id' => $this->dUser()->id]);

        $this->assertSame(['Tiling from a friend'], $this->titles($this->search('tiling')));
    }

    public function test_anonymous_professionals_are_found_by_headline_never_by_name_and_only_by_employers(): void
    {
        $pro = $this->dUser(['skills' => ['welding'], 'discoverability' => 'anonymous', 'place' => $this->geo->jhb], ['name' => 'Zanele Dlamini', 'email' => 'zanele.hidden@example.test']);
        $profileId = DB::table('pulse_profiles')->where('user_id', $pro->id)->value('id');
        $this->dSignal(['kind' => 'talent', 'subject_type' => 'profile', 'subject_id' => $profileId, 'owner_id' => $pro->id, 'anonymous' => true, 'title' => 'Certified coded welder', 'skills' => ['welding'], 'place' => $this->geo->jhb]);

        // Not an employer: nothing, whatever you type.
        $this->assertSame([], $this->titles($this->search('welder')));

        $this->dEntity($this->me, ['is_employer' => true]);

        $by = $this->search('welder');
        $this->assertSame(['Certified coded welder'], $this->titles($by));
        $this->assertSame([], $this->search('Zanele')['results'], 'the name is not searchable');
        $this->assertSame([], $this->search('zanele.hidden')['results'], 'neither is the email');

        $dump = json_encode(array_map(fn ($r) => $r['beat']->toArray(), $by['results'])).serialize($by['results'][0]['beat']->signal->toArray());
        $this->assertStringNotContainsString('Zanele', $dump);
        $this->assertStringNotContainsString('hidden@example', $dump);
        $this->assertNull($by['results'][0]['beat']->signal->owner_id);
    }

    public function test_talent_whose_profile_went_private_or_is_a_minor_disappears(): void
    {
        $this->dEntity($this->me, ['is_employer' => true]);
        $pro = $this->dUser(['skills' => ['welding'], 'discoverability' => 'anonymous', 'place' => $this->geo->jhb]);
        $profileId = DB::table('pulse_profiles')->where('user_id', $pro->id)->value('id');
        $this->dSignal(['kind' => 'talent', 'subject_type' => 'profile', 'subject_id' => $profileId, 'owner_id' => $pro->id, 'anonymous' => true, 'title' => 'Coded welder', 'skills' => ['welding']]);
        $this->assertCount(1, $this->search('welder')['results']);

        DB::table('pulse_profiles')->where('id', $profileId)->update(['discoverability' => 'off']);
        $this->assertCount(0, $this->search('welder')['results']);

        DB::table('pulse_profiles')->where('id', $profileId)->update(['discoverability' => 'open', 'age_confirmed_at' => null]);
        $this->assertCount(0, $this->search('welder')['results']);
    }

    public function test_filters(): void
    {
        $seller = $this->dEntity($this->dUser());
        $cheap = $this->dOffer($seller, ['title' => 'Paving cheap', 'amount_cents' => 50000]);
        $dear = $this->dOffer($seller, ['title' => 'Paving premium', 'amount_cents' => 900000]);
        foreach ([$cheap, $dear] as $o) {
            $this->dSignal(['kind' => 'offer', 'subject_type' => 'offer', 'subject_id' => $o->id, 'entity_id' => $seller->id, 'owner_id' => $seller->created_by, 'title' => $o->title, 'place' => $this->geo->jhb]);
        }
        $role = $this->dRole($seller, ['title' => 'Paving foreman', 'employment_type' => 'contract']);
        $this->dSignal(['kind' => 'role', 'subject_type' => 'role', 'subject_id' => $role->id, 'entity_id' => $seller->id, 'owner_id' => $seller->created_by, 'title' => $role->title, 'is_remote' => true, 'place' => $this->geo->cpt]);
        $this->dSignal(['kind' => 'news', 'subject_type' => 'post', 'owner_id' => $this->dUser()->id, 'title' => 'Paving news', 'place' => $this->geo->jhb]);

        $this->assertEqualsCanonicalizing(['Paving cheap', 'Paving premium', 'Paving foreman', 'Paving news'], array_diff($this->titles($this->search('paving')), []));
        $this->assertSame(['Paving cheap'], $this->titles($this->search('paving', ['price_max' => 1000])));
        $this->assertSame(['Paving premium'], $this->titles($this->search('paving', ['price_min' => 5000])));
        $this->assertSame(['Paving foreman'], $this->titles($this->search('paving', ['employment_type' => 'contract'])));
        $this->assertSame([], $this->titles($this->search('paving', ['employment_type' => ['full_time']])));
        $this->assertSame(['Paving foreman'], $this->titles($this->search('paving', ['remote' => true])));
        $this->assertSame(['Paving news'], $this->titles($this->search('paving', ['kind' => 'news'])));
        $this->assertEqualsCanonicalizing(['Paving cheap', 'Paving premium'], $this->titles($this->search('paving', ['kind' => ['offer']])));
        $this->assertEqualsCanonicalizing(['Paving foreman'], $this->titles($this->search('paving', ['mode' => 'work'])));
        $this->assertEqualsCanonicalizing(['Paving news'], $this->titles($this->search('paving', ['mode' => 'learn'])));
        $this->assertEqualsCanonicalizing(['Paving cheap', 'Paving premium', 'Paving news'], $this->titles($this->search('paving', ['place' => $this->geo->jhb->slug])));
        $this->assertEqualsCanonicalizing(['Paving cheap', 'Paving premium', 'Paving news'], $this->titles($this->search('paving', ['mode' => 'local'])));
    }

    public function test_nothing_typed_and_no_filter_is_an_empty_answer_not_everything(): void
    {
        $this->dSignal(['title' => 'Anything', 'owner_id' => $this->dUser()->id]);

        $this->assertSame([], $this->search('')['results']);
        $this->assertSame([], $this->search(' x ')['results'], 'one letter is not a search');
    }

    public function test_like_wildcards_in_the_query_are_literal(): void
    {
        $this->dSignal(['title' => 'Tiling 100% guaranteed', 'owner_id' => $this->dUser()->id]);
        $this->dSignal(['title' => 'Tiling only', 'owner_id' => $this->dUser()->id]);

        $this->assertSame(['Tiling 100% guaranteed'], $this->titles($this->search('100%')));
        $this->assertSame([], $this->search('%%')['results']);
    }

    public function test_results_are_paged(): void
    {
        foreach (range(1, 25) as $i) {
            $this->dSignal(['title' => "Roofing job {$i}", 'owner_id' => $this->dUser()->id, 'published_at' => now()->subMinutes($i)]);
        }

        $one = $this->search('roofing');
        $this->assertCount(20, $one['results']);
        $this->assertSame(25, $one['total']);
        $this->assertNotNull($one['next']);

        $two = $this->search('roofing', [], $one['next']);
        $this->assertCount(5, $two['results']);
        $this->assertNull($two['next']);
        $this->assertEmpty(array_intersect($this->titles($one), $this->titles($two)));
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_results(): void
    {
        $count = function (int $n) {
            PulseSignal::query()->delete();
            for ($i = 0; $i < $n; $i++) {
                $e = $this->dEntity($this->dUser());
                $this->dOfferSignal($e, ['roofing'], ['title' => "Roofing {$i}", 'place' => $this->geo->jhb]);
                $this->dSignal(['title' => "Roofing news {$i}", 'kind' => 'news', 'subject_type' => 'post', 'owner_id' => $this->dUser()->id]);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->search('roofing');
            $q = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $q;
        };

        $this->assertSame($count(2), $count(12));
        $this->assertLessThanOrEqual(30, $count(12));
    }

    public function test_suggestions_come_from_the_taxonomy_businesses_and_live_titles_only(): void
    {
        $this->dEntity($this->dUser(), ['name' => 'Plumb Perfect']);
        $this->dEntity($this->dUser(), ['name' => 'Plumb Draft', 'status' => 'draft']);
        $this->dSignal(['title' => 'Plumbing geyser special', 'owner_id' => $this->dUser()->id]);
        $this->dSignal(['title' => 'Plumbing held special', 'status' => 'held', 'owner_id' => $this->dUser()->id]);
        $blocked = $this->dUser();
        $this->dBlock($this->me, $blocked);
        $this->dSignal(['title' => 'Plumbing from blocked', 'owner_id' => $blocked->id]);

        $labels = array_column(app(SearchService::class)->suggest($this->me, 'plumb'), 'label');

        $this->assertContains('Plumbing', $labels);
        $this->assertContains('Plumb Perfect', $labels);
        $this->assertContains('Plumbing geyser special', $labels);
        $this->assertNotContains('Plumb Draft', $labels);
        $this->assertNotContains('Plumbing held special', $labels);
        $this->assertNotContains('Plumbing from blocked', $labels);
        $this->assertSame([], app(SearchService::class)->suggest($this->me, 'p'));
    }

    public function test_closeness_breaks_ties(): void
    {
        $this->dSignal(['title' => 'Paving in Cape Town', 'place' => $this->geo->cpt, 'owner_id' => $this->dUser()->id, 'published_at' => now()->subHour()]);
        $this->dSignal(['title' => 'Paving in Sandton', 'place' => $this->geo->sandton, 'owner_id' => $this->dUser()->id, 'published_at' => now()->subHour()]);

        $res = $this->search('paving');

        $this->assertSame('Paving in Sandton', $this->titles($res)[0]);
        $this->assertContains('They are in your city.', $res['results'][0]['why']);
    }
}
