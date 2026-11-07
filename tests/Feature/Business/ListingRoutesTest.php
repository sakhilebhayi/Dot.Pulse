<?php

namespace Tests\Feature\Business;

use App\Models\PulseOffer;
use App\Models\PulseRole;

class ListingRoutesTest extends BusinessTestCase
{
    public function test_a_member_reads_an_offer_with_price_knock_and_quote(): void
    {
        $page = $this->page(null, ['name' => 'Moyo Books']);
        $offer = PulseOffer::factory()->create(['entity_id' => $page->id, 'created_by' => $page->created_by, 'title' => 'Payroll', 'slug' => 'payroll', 'amount_cents' => 150000, 'price_mode' => 'from', 'unit' => 'month', 'accepts_quotes' => true]);

        $this->actingAs($this->person())->get(route('offers.show', ['entity' => $page->slug, 'offer' => 'payroll']))
            ->assertOk()->assertSee('Payroll')->assertSee('R1 500')->assertSee('From this price')
            ->assertSeeLivewire('pulse.knock-sheet')->assertSeeLivewire('pulse.business.quote-request')->assertSeeLivewire('pulse.business.listing-actions');
    }

    public function test_a_guest_is_sent_to_sign_in_for_an_offer_or_role(): void
    {
        $page = $this->page();
        $offer = PulseOffer::factory()->create(['entity_id' => $page->id, 'created_by' => $page->created_by, 'slug' => 'o1']);
        $role = PulseRole::factory()->create(['entity_id' => $page->id, 'created_by' => $page->created_by, 'slug' => 'r1']);

        $this->get(route('offers.show', ['entity' => $page->slug, 'offer' => $offer->slug]))->assertRedirect();
        $this->get(route('roles.show', ['entity' => $page->slug, 'role' => $role->slug]))->assertRedirect();
    }

    public function test_an_offer_that_is_a_draft_or_held_is_a_404_for_others_and_visible_to_the_team(): void
    {
        $page = $this->page();
        $draft = PulseOffer::factory()->draft()->create(['entity_id' => $page->id, 'created_by' => $page->created_by, 'slug' => 'draft']);
        $held = PulseOffer::factory()->pendingModeration()->create(['entity_id' => $page->id, 'created_by' => $page->created_by, 'slug' => 'held']);
        $owner = $page->members()->first()->user;

        $this->actingAs($this->person())->get(route('offers.show', ['entity' => $page->slug, 'offer' => 'draft']))->assertNotFound();
        $this->actingAs($this->person())->get(route('offers.show', ['entity' => $page->slug, 'offer' => 'held']))->assertNotFound();

        $this->actingAs($owner)->get(route('offers.show', ['entity' => $page->slug, 'offer' => 'draft']))->assertOk()->assertSee('a draft');
        $this->actingAs($owner)->get(route('offers.show', ['entity' => $page->slug, 'offer' => 'held']))->assertOk()->assertSee('Waiting for a human');
    }

    public function test_an_offer_under_the_wrong_page_is_a_404(): void
    {
        $a = $this->page();
        $b = $this->page();
        PulseOffer::factory()->create(['entity_id' => $a->id, 'created_by' => $a->created_by, 'slug' => 'shared']);

        $this->actingAs($this->person())->get(route('offers.show', ['entity' => $b->slug, 'offer' => 'shared']))->assertNotFound();
    }

    public function test_sold_out_availability_is_said(): void
    {
        $page = $this->page();
        PulseOffer::factory()->create(['entity_id' => $page->id, 'created_by' => $page->created_by, 'slug' => 'gone', 'availability' => 'sold_out']);

        $this->actingAs($this->person())->get(route('offers.show', ['entity' => $page->slug, 'offer' => 'gone']))->assertOk()->assertSee('Sold out');
    }

    public function test_a_role_page_shows_details_and_apply_on_pulse(): void
    {
        $page = $this->page(null, ['name' => 'Moyo Books']);
        PulseRole::factory()->create(['entity_id' => $page->id, 'created_by' => $page->created_by, 'slug' => 'junior', 'title' => 'Junior bookkeeper', 'skills' => ['excel'], 'pay_text' => 'R15 000 a month', 'closes_at' => now()->addDays(10), 'apply_mode' => 'on_pulse']);

        $this->actingAs($this->person())->get(route('roles.show', ['entity' => $page->slug, 'role' => 'junior']))
            ->assertOk()->assertSee('Junior bookkeeper')->assertSee('R15 000 a month')->assertSee('excel')->assertSee('Closes on')->assertSee('Open')
            ->assertSeeLivewire('pulse.knock-sheet')->assertSee('It never rejects anyone');
    }

    public function test_an_external_apply_role_links_out_and_says_so(): void
    {
        $page = $this->page();
        PulseRole::factory()->create(['entity_id' => $page->id, 'created_by' => $page->created_by, 'slug' => 'ext', 'apply_mode' => 'external_url', 'apply_url' => 'https://jobs.example.test/apply']);

        $this->actingAs($this->person())->get(route('roles.show', ['entity' => $page->slug, 'role' => 'ext']))
            ->assertOk()->assertSee('https://jobs.example.test/apply', false)->assertSee('You leave Pulse')->assertDontSeeLivewire('pulse.knock-sheet');
    }

    public function test_a_closed_or_filled_role_still_answers_with_its_status_and_no_apply(): void
    {
        $page = $this->page();
        PulseRole::factory()->create(['entity_id' => $page->id, 'created_by' => $page->created_by, 'slug' => 'done', 'status' => 'filled']);
        PulseRole::factory()->create(['entity_id' => $page->id, 'created_by' => $page->created_by, 'slug' => 'shut', 'status' => 'closed']);
        $visitor = $this->person();

        $this->actingAs($visitor)->get(route('roles.show', ['entity' => $page->slug, 'role' => 'done']))->assertOk()->assertSee('Filled')->assertSee('was filled')->assertDontSeeLivewire('pulse.knock-sheet');
        $this->actingAs($visitor)->get(route('roles.show', ['entity' => $page->slug, 'role' => 'shut']))->assertOk()->assertSee('Closed')->assertSee('no longer takes applications');
    }

    public function test_a_draft_role_is_a_404_for_others(): void
    {
        $page = $this->page();
        PulseRole::factory()->draft()->create(['entity_id' => $page->id, 'created_by' => $page->created_by, 'slug' => 'wip']);

        $this->actingAs($this->person())->get(route('roles.show', ['entity' => $page->slug, 'role' => 'wip']))->assertNotFound();
    }

    public function test_manage_routes_by_role(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;
        $admin = $this->member($page, 'admin');
        $editor = $this->member($page, 'editor');
        $responder = $this->member($page, 'responder');
        $stranger = $this->person();

        foreach (['entities.manage', 'entities.manage.offers', 'entities.manage.roles', 'entities.manage.team', 'entities.manage.lines', 'entities.manage.verify', 'entities.manage.page'] as $name) {
            $this->actingAs($owner)->get(route($name, ['entity' => $page->slug]))->assertOk();
            $this->actingAs($stranger)->get(route($name, ['entity' => $page->slug]))->assertNotFound();
        }

        $this->actingAs($admin)->get(route('entities.manage.team', ['entity' => $page->slug]))->assertOk();
        $this->actingAs($admin)->get(route('entities.manage.verify', ['entity' => $page->slug]))->assertOk();

        $this->actingAs($editor)->get(route('entities.manage', ['entity' => $page->slug]))->assertOk();
        $this->actingAs($editor)->get(route('entities.manage.offers', ['entity' => $page->slug]))->assertOk();
        $this->actingAs($editor)->get(route('entities.manage.team', ['entity' => $page->slug]))->assertForbidden();
        $this->actingAs($editor)->get(route('entities.manage.verify', ['entity' => $page->slug]))->assertForbidden();
        $this->actingAs($editor)->get(route('entities.manage.lines', ['entity' => $page->slug]))->assertForbidden();

        $this->actingAs($responder)->get(route('entities.manage', ['entity' => $page->slug]))->assertOk();
        $this->actingAs($responder)->get(route('entities.manage.lines', ['entity' => $page->slug]))->assertOk();
        $this->actingAs($responder)->get(route('entities.manage.offers', ['entity' => $page->slug]))->assertForbidden();
        $this->actingAs($responder)->get(route('entities.manage.roles', ['entity' => $page->slug]))->assertForbidden();
    }

    public function test_the_overview_shows_real_to_dos_and_hides_empty_numbers(): void
    {
        $page = $this->page(null, ['about' => null]);
        $owner = $page->members()->first()->user;

        $this->actingAs($owner)->get(route('entities.manage', ['entity' => $page->slug]))
            ->assertOk()->assertSee('Write your first offer')->assertSee('Write an about')->assertSee('Link your InfoDot company')
            ->assertSee('No numbers yet')->assertDontSee('Offers on the floor');
    }

    public function test_manage_tabs_render_their_components(): void
    {
        $page = $this->page();
        $owner = $page->members()->first()->user;

        $this->actingAs($owner)->get(route('entities.manage.offers', ['entity' => $page->slug]))->assertSeeLivewire('pulse.business.offer-manager');
        $this->actingAs($owner)->get(route('entities.manage.roles', ['entity' => $page->slug]))->assertSeeLivewire('pulse.business.role-manager');
        $this->actingAs($owner)->get(route('entities.manage.team', ['entity' => $page->slug]))->assertSeeLivewire('pulse.business.team-manager');
        $this->actingAs($owner)->get(route('entities.manage.verify', ['entity' => $page->slug]))->assertSeeLivewire('pulse.business.verify-panel');
        $this->actingAs($owner)->get(route('entities.manage.page', ['entity' => $page->slug]))->assertSeeLivewire('pulse.business.entity-form');
    }
}
