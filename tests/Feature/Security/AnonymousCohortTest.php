<?php

namespace Tests\Feature\Security;

use App\Livewire\Pulse\Floor\BeatDetail;
use App\Models\PulseMatch;
use App\Models\PulseProfile;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\AnonymousCohort;
use App\Services\Pulse\Discovery\Floor;
use App\Services\Pulse\Discovery\Matcher;
use App\Services\Pulse\Discovery\SearchService;
use App\Services\Pulse\Entities\ProfileService;
use App\Services\Pulse\Entities\SignalIndexer;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\LineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\DiscoveryFixtures;
use Tests\TestCase;

/**
 * K-anonymity for anonymous talent cards (D23). An employer is shown an individual anonymous card only when five or
 * more professionals share its primary skills and region; whatever filters they combine, they never get a list that
 * is narrower than that, and two searches cannot differ by one person.
 */
class AnonymousCohortTest extends TestCase
{
    use DiscoveryFixtures;
    use RefreshDatabase;

    private object $geo;

    private User $employer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pulse.privacy.talent_cohort_min' => 5, 'pulse.entities.rules_class' => 'Tests\\Absent\\Rules']);
        $this->geo = $this->dGeo();
        $this->employer = $this->dUser(['place' => $this->geo->jhb]);
        $this->onboard($this->employer);
        $page = $this->dEntity($this->employer, ['name' => 'Karoo Logistics', 'is_employer' => true, 'place_id' => $this->geo->jhb->id]);
        $this->dRole($page, ['skills' => ['welding']]);
    }

    /** @return array{0: User, 1: PulseSignal} */
    private function pro(array $skills = ['welding'], string $mode = 'anonymous', $place = null, string $headline = 'Welder', array $extra = []): array
    {
        $place ??= $this->geo->jhb;
        $u = $this->dUser(['place' => $place]);
        $service = app(ProfileService::class);
        $service->confirmAge($u);
        $service->update($u, array_merge(['headline' => $headline, 'skills' => $skills, 'place_id' => $place->id, 'experience_years' => 6], $extra));
        $service->setDiscoverability($u, $mode);
        $profile = PulseProfile::where('user_id', $u->id)->first();
        app(SignalIndexer::class)->syncSubject($profile);

        return [$u, PulseSignal::forSubject('profile', $profile->id)->first()];
    }

    /** @return list<int> signal ids of the cards a search shows the employer */
    private function found(string $q, array $filters = []): array
    {
        return collect(app(SearchService::class)->search($this->employer, $q, $filters)['results'])
            ->where('type', 'signal')->map(fn ($r) => $r['beat']->signal->id)->values()->all();
    }

    private function floorIds(string $mode = 'work'): array
    {
        app(Matcher::class)->forUser($this->employer);

        return array_map(fn ($b) => $b->signal->id, app(Floor::class)->round($this->employer, $mode)['beats']);
    }

    private function many(int $n, array $skills = ['welding'], string $mode = 'anonymous', $place = null): array
    {
        return array_map(fn () => $this->pro($skills, $mode, $place)[1], range(1, $n));
    }

    public function test_four_alike_are_not_shown_anywhere_and_the_fifth_unlocks_all_of_them(): void
    {
        $signals = $this->many(4);

        $this->assertSame([], $this->floorIds(), 'Floor');
        $this->assertSame([], $this->found('welding'), 'search');
        $this->assertSame([], $this->found('', ['kind' => 'talent']), 'search by kind');
        $this->assertSame([], $this->found('', ['mode' => 'work', 'place' => $this->geo->jhb->slug]), 'search by mode and place');
        $this->assertSame(0, PulseMatch::where('user_id', $this->employer->id)->count(), 'no stored match either');

        $this->actingAs($this->employer);
        Livewire::test(BeatDetail::class, ['signal' => $signals[0]])->assertStatus(404);
        $this->assertDeniedInvite($signals[0]);

        [, $fifth] = $this->pro();
        $all = array_merge(array_map(fn ($s) => $s->id, $signals), [$fifth->id]);

        $this->assertEqualsCanonicalizing($all, $this->floorIds(), 'five: the Floor shows them');
        $this->assertEqualsCanonicalizing($all, $this->found('welding'));
        Livewire::test(BeatDetail::class, ['signal' => $signals[0]])->assertOk();
    }

    private function assertDeniedInvite(PulseSignal $signal): void
    {
        $page = \App\Models\PulseEntity::query()->where('name', 'Karoo Logistics')->first();

        try {
            app(LineService::class)->knock($this->employer, ['signal' => $signal->id, 'as_entity' => $page->id], 'invite', ['what' => 'We would like to talk about a welding role']);
            $this->fail('An anonymous card outside a cohort of five must not be invitable.');
        } catch (LineException $e) {
            $this->assertSame('unavailable', $e->reason);
        }
    }

    public function test_a_cohort_of_five_can_be_invited(): void
    {
        $signals = $this->many(5);
        $page = \App\Models\PulseEntity::query()->where('name', 'Karoo Logistics')->first();

        $line = app(LineService::class)->knock($this->employer, ['signal' => $signals[0]->id, 'as_entity' => $page->id], 'invite', ['what' => 'We would like to talk about a welding role']);

        $this->assertNotNull($line->id);
    }

    public function test_narrowing_the_filters_cannot_isolate_one_person_the_differencing_attack(): void
    {
        // Five welders and one welder who also lists a rare second skill. Before the rule, searching the rare skill
        // returned exactly one card: the sixth person, identified by subtraction from the broad search.
        $five = array_map(fn ($s) => $s->id, $this->many(5));
        [, $rare] = $this->pro(['welding', 'glassblowing']);

        $broad = $this->found('welding');
        $narrow = $this->found('glassblowing');
        $narrowest = $this->found('welding glassblowing', ['mode' => 'work', 'place' => $this->geo->jhb->slug, 'kind' => 'talent']);

        $this->assertEqualsCanonicalizing($five, $broad, 'the broad search shows the five, never the sixth');
        $this->assertSame([], $narrow, 'one rare skill never singles out a person');
        $this->assertEqualsCanonicalizing($five, $narrowest, 'terms widen a search; they never narrow it to the sixth');
        $this->assertNotContains($rare->id, $broad);
        $this->assertNotContains($rare->id, $this->floorIds());

        // And the reverse subtraction: whichever filters are used, the result set is a union of whole cohorts.
        foreach (['welding', 'weld', 'glassblowing', ''] as $q) {
            $ids = $this->found($q, ['kind' => 'talent']);
            $this->assertTrue($ids === [] || count($ids) >= 5, "a search for '{$q}' returned ".count($ids).' cards');
        }
    }

    public function test_the_cohort_is_the_cards_skills_and_region_not_the_search(): void
    {
        // Same skills, but 4 in Gauteng and 1 in the Western Cape: neither region reaches five.
        $this->many(4);
        $this->pro(['welding'], 'anonymous', $this->geo->cpt);

        $this->assertSame([], $this->found('welding'));

        // Reaching five in Gauteng shows only the Gauteng five, not the lone Cape Town card.
        [, $fifth] = $this->pro();
        $found = $this->found('welding');

        $this->assertCount(5, $found);
        $this->assertContains($fifth->id, $found);
    }

    public function test_city_and_suburb_are_one_region_and_open_professionals_count_towards_a_cohort(): void
    {
        $this->pro(['welding'], 'anonymous', $this->geo->sandton);
        $this->pro(['welding'], 'anonymous', $this->geo->pta);
        $this->pro(['welding'], 'open', $this->geo->jhb);
        $this->pro(['welding'], 'open', $this->geo->jhb);
        // Four so far: the two anonymous cards are hidden, the two open ones are shown.
        $this->assertCount(2, $this->found('welding'), 'open professionals chose to be found by name, so they are never held back');

        $this->pro(['welding'], 'anonymous', $this->geo->jhb);
        $this->assertCount(5, $this->found('welding'), 'the fifth member unlocks the anonymous cards, and the open ones stay');
    }

    public function test_people_who_exclude_this_employer_do_not_count_towards_its_cohort(): void
    {
        $this->many(4);
        $this->pro(['welding'], 'anonymous', null, 'Welder', ['work_history' => [['title' => 'Welder', 'employer' => 'Karoo Logistics', 'hide_from_employer' => true]]]);
        $this->assertSame([], $this->found('welding'), 'a fifth who hides from this employer is not a member');

        $blocked = $this->dUser();
        $this->pro();
        $this->assertCount(5, $this->found('welding'), 'a genuine fifth completes it');

        // Someone the employer blocked leaves the cohort again.
        [$sixth] = $this->pro();
        $this->assertCount(6, $this->found('welding'));
        \Illuminate\Support\Facades\DB::table('pulse_blocks')->insert(['blocker_id' => $this->employer->id, 'blocked_id' => $sixth->id, 'created_at' => now(), 'updated_at' => now()]);
        AnonymousCohort::flush();
        $this->assertCount(5, $this->found('welding'));
        $this->assertNotNull($blocked);
    }

    public function test_anonymous_cards_are_found_on_skills_and_region_only(): void
    {
        $this->many(5);
        [, $odd] = $this->pro(['welding'], 'anonymous', null, 'Zephyrine fitter');

        // The headline, interests and experience band are free text and cannot select a card.
        $this->assertSame([], $this->found('zephyrine'));
        $this->assertSame([], $this->found('fitter'));
        $this->assertSame([], $this->found('6 to 10 years'));
        $this->assertContains($odd->id, $this->found('welding'), 'the card is still found by its skill');

        // The remote switch is not a cohort attribute either.
        $this->assertSame([], array_values(array_diff($this->found('welding', ['remote' => true]), [])));
    }

    public function test_a_card_with_no_skills_is_never_shown_to_an_employer(): void
    {
        $this->many(5);
        \Illuminate\Support\Facades\DB::table('pulse_signals')->where('kind', 'talent')->update(['skills' => '[]']);

        $this->assertSame([], $this->found('', ['kind' => 'talent']));
    }

    public function test_an_open_professional_alone_is_still_found(): void
    {
        [, $open] = $this->pro(['welding'], 'open');

        $this->assertSame([$open->id], $this->found('welding'));
    }

    public function test_the_hiring_signal_count_is_a_band_and_null_below_five(): void
    {
        $this->many(4);
        $page = \App\Models\PulseEntity::query()->where('name', 'Karoo Logistics')->first();
        $page->forceFill(['hiring_signal' => 'active', 'hiring_skills' => ['Welding'], 'place_id' => $this->geo->jhb->id])->save();

        $count = app(\App\Services\Pulse\Entities\TalentCohort::class);
        $this->assertNull($count->countFor($page));

        $this->pro();
        $this->assertSame(5, $count->countFor($page));
    }
}
