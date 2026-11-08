<?php

namespace Tests\Feature\Floor;

use App\Livewire\Pulse\Floor\Search;
use App\Models\PulseNeed;
use App\Services\Pulse\Discovery\Taxonomy;
use Livewire\Livewire;

class SearchTest extends FloorTestCase
{
    private function search(array $query = [])
    {
        return Livewire::actingAs($this->me)->withQueryParams($query)->test(Search::class);
    }

    public function test_a_blank_search_invites_a_search_and_shows_no_results(): void
    {
        $this->search()->assertSee('Say what you are looking for.')->assertSet('beats', []);
    }

    public function test_results_are_beats_and_say_why_they_matched(): void
    {
        [$signal] = $this->offerFor('Bookkeeping for small shops');

        $this->search(['q' => 'bookkeeping'])
            ->assertSee('Bookkeeping for small shops')
            ->assertSee('Your words are in the title.')
            ->assertSee('1 result')
            ->assertSee('data-beat="'.$signal->id.'"', false);
    }

    public function test_the_url_carries_the_whole_search(): void
    {
        $this->offerFor('Bookkeeping for small shops');

        $this->search(['q' => 'bookkeeping', 'kind' => 'offer', 'remote' => '1'])
            ->assertSet('q', 'bookkeeping')
            ->assertSet('kind', 'offer')
            ->assertSet('remote', true);
    }

    public function test_typing_runs_the_search_and_filters_narrow_it(): void
    {
        $this->offerFor('Bookkeeping for small shops');
        $this->dNeed($this->person(), ['title' => 'Need a bookkeeping hand']);
        $this->dSignal(['kind' => 'need', 'subject_type' => 'need', 'subject_id' => PulseNeed::latest('id')->value('id'), 'owner_id' => PulseNeed::latest('id')->value('user_id'), 'title' => 'Need a bookkeeping hand']);

        $titles = fn ($c) => collect($c->get('beats'))->pluck('title')->all();

        $c = $this->search()->set('q', 'bookkeeping');
        $this->assertEqualsCanonicalizing(['Bookkeeping for small shops', 'Need a bookkeeping hand'], $titles($c));

        $c->set('kind', 'need');
        $this->assertSame(['Need a bookkeeping hand'], $titles($c));

        $c->set('kind', '');
        $this->assertCount(2, $c->get('beats'));
    }

    public function test_the_place_filter_uses_the_gazetteer_typeahead(): void
    {
        $this->offerFor('Bookkeeping in Johannesburg');
        $cape = $this->dEntity($this->person(), ['name' => 'Cape Books']);
        $this->dOfferSignal($cape, ['bookkeeping'], ['title' => 'Bookkeeping in Cape Town', 'place' => $this->geo->cpt]);

        $c = $this->search(['q' => 'bookkeeping'])->assertSee('Bookkeeping in Johannesburg')->assertSee('Bookkeeping in Cape Town');

        $c->set('placeText', 'cape tow')->assertSee('Cape Town');
        $c->call('pickPlace', $this->geo->cpt->slug)
            ->assertSet('place', $this->geo->cpt->slug)
            ->assertSee('Bookkeeping in Cape Town')
            ->assertDontSee('Bookkeeping in Johannesburg')
            ->assertSee('Clear place');

        $c->call('clearPlace')->assertSee('Bookkeeping in Johannesburg');
    }

    public function test_an_unknown_place_slug_is_ignored(): void
    {
        $this->search(['q' => 'x'])->call('pickPlace', 'atlantis')->assertSet('place', '');
    }

    public function test_the_url_is_user_input_and_is_cleaned(): void
    {
        $this->search(['q' => str_repeat('a', 400), 'kind' => 'drop table', 'mode' => 'weird', 'min' => 'abc', 'type' => 'x', 'place' => 'Not A Slug!'])
            ->assertSet('kind', '')
            ->assertSet('mode', '')
            ->assertSet('priceMin', '')
            ->assertSet('employment', '')
            ->assertSet('place', '');
    }

    public function test_typeahead_suggestions_come_from_the_taxonomy_businesses_and_listings(): void
    {
        $this->offerFor('Bookkeeping for small shops');

        $this->search()->set('q', 'book')->assertSee('Suggestions')->assertSee('Bookkeeping');
    }

    public function test_picking_a_suggestion_searches_for_it(): void
    {
        $this->offerFor('Bookkeeping for small shops');

        $this->search()->set('q', 'book')->call('pick', 'Bookkeeping for small shops')->assertSet('q', 'Bookkeeping for small shops')->assertSee('1 result');
    }

    public function test_businesses_are_results_too(): void
    {
        $this->dEntity($this->person(), ['name' => 'Highveld Cycle Works', 'tagline' => 'Repairs and custom builds']);

        $this->search(['q' => 'highveld'])->assertSee('Highveld Cycle Works')->assertSee('The business name fits.');
    }

    public function test_no_results_suggests_posting_a_need(): void
    {
        $this->search(['q' => 'zzzzqx'])
            ->assertSee('Nothing found for zzzzqx.')
            ->assertSee('Say what you need and the floor will find it for you when something does.')
            ->assertSee('Say what you need');
    }

    public function test_search_never_shows_blocked_held_or_muted_things(): void
    {
        $blocked = $this->person();
        $this->dSignal(['owner_id' => $blocked->id, 'kind' => 'ask', 'subject_type' => 'post', 'title' => 'Cycling question from blocked']);
        $this->dBlock($this->me, $blocked);

        $this->dSignal(['owner_id' => $this->person()->id, 'kind' => 'ask', 'subject_type' => 'post', 'title' => 'Cycling question held', 'status' => 'held']);
        $this->dSignal(['owner_id' => $this->person()->id, 'kind' => 'ask', 'subject_type' => 'post', 'title' => 'Cycling question fine']);

        $this->search(['q' => 'cycling'])
            ->assertSee('Cycling question fine')
            ->assertDontSee('Cycling question from blocked')
            ->assertDontSee('Cycling question held');
    }

    public function test_anonymous_professionals_are_found_only_by_employers_and_never_by_name(): void
    {
        $pro = $this->person(['discoverability' => 'anonymous', 'skills' => ['payroll']], ['name' => 'Zodwa Hiddenname']);
        $profileId = \DB::table('pulse_profiles')->where('user_id', $pro->id)->value('id');
        $this->dSignal(['kind' => 'talent', 'subject_type' => 'profile', 'subject_id' => $profileId, 'owner_id' => $pro->id, 'anonymous' => true, 'title' => 'Payroll specialist', 'skills' => ['payroll']]);

        $this->search(['q' => 'payroll'])->assertDontSee('Payroll specialist');

        $this->dEntity($this->me, ['name' => 'Employer Page', 'is_employer' => true]);
        $this->search(['q' => 'payroll'])->assertSee('Payroll specialist')->assertSee('Anonymous professional')->assertDontSee('Zodwa')->assertDontSee('Hiddenname');
        $this->search(['q' => 'zodwa'])->assertDontSee('Payroll specialist');
    }

    public function test_show_more_results_continues_the_list(): void
    {
        for ($i = 0; $i < 23; $i++) {
            $this->dSignal(['owner_id' => $this->person()->id, 'kind' => 'ask', 'subject_type' => 'post', 'title' => "Cycling question {$i}"]);
        }

        $c = $this->search(['q' => 'cycling']);
        $this->assertCount(20, $c->get('beats'));
        $c->assertSee('Show more results')->call('more');
        $this->assertCount(23, $c->get('beats'));
        $c->assertDontSee('Show more results');
    }

    public function test_save_works_from_a_result(): void
    {
        [$signal] = $this->offerFor('Bookkeeping for small shops');

        $this->search(['q' => 'bookkeeping'])->call('save', $signal->id)->assertSee('Saved')->assertDispatched('pulse-saved-changed');

        $this->assertDatabaseHas('pulse_saves', ['user_id' => $this->me->id, 'saveable_id' => $signal->id]);
    }

    public function test_the_page_route_renders_with_a_query(): void
    {
        $this->offerFor('Bookkeeping for small shops');

        $this->actingAs($this->me)->get(route('search', ['q' => 'bookkeeping']))->assertOk()->assertSee('Bookkeeping for small shops');
    }

    public function test_a_search_closes_the_suggestions_and_never_suggests_from_the_middle_of_a_word_or_an_alias(): void
    {
        $this->search(['q' => 'plum'])->set('q', 'plumbing')->assertSet('suggestOpen', true)->call('submit')->assertSet('suggestOpen', false);

        $labels = array_column((new Taxonomy)->suggest('plumbing', 'any', 8), 'label');
        $this->assertContains('Plumbing', $labels);
        $this->assertNotContains('Pool building', $labels, 'an alias like "pool plumbing" is not a reason to suggest pools');
    }
}
