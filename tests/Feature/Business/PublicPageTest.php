<?php

namespace Tests\Feature\Business;

use App\Models\PulseOffer;
use App\Models\PulseRole;

class PublicPageTest extends BusinessTestCase
{
    public function test_a_guest_sees_a_limited_card_with_titles_and_no_private_data(): void
    {
        $owner = $this->infodotPerson();
        $page = $this->page($owner, ['name' => 'Moyo Books', 'tagline' => 'Books done right', 'about' => 'We do the books.', 'public_email' => 'hello@moyobooks.test', 'public_phone' => '011 555 0100', 'website' => 'https://moyobooks.test', 'registration_number' => '2019/123456/07']);
        $teammate = $this->member($page, 'editor');
        PulseOffer::factory()->create(['entity_id' => $page->id, 'created_by' => $owner->id, 'title' => 'Payroll for ten staff', 'summary' => 'SECRET-SUMMARY-TEXT', 'slug' => 'payroll']);
        PulseRole::factory()->create(['entity_id' => $page->id, 'created_by' => $owner->id, 'title' => 'Junior bookkeeper', 'summary' => 'SECRET-ROLE-SUMMARY', 'slug' => 'junior']);

        $response = $this->get(route('entities.show', ['entity' => $page->slug]));

        $response->assertOk()
            ->assertSee('Moyo Books')->assertSee('Books done right')->assertSee('We do the books.')
            ->assertSee('Payroll for ten staff')->assertSee('Junior bookkeeper')
            ->assertSee('Sign in with InfoDot to knock')
            // no member list, no private or member-only data
            ->assertDontSee($owner->name)->assertDontSee($owner->email)->assertDontSee($teammate->name)->assertDontSee($teammate->email)
            ->assertDontSee('hello@moyobooks.test')->assertDontSee('011 555 0100')->assertDontSee('moyobooks.test')->assertDontSee('2019/123456/07')
            ->assertDontSee('SECRET-SUMMARY-TEXT')->assertDontSee('SECRET-ROLE-SUMMARY')
            ->assertDontSee('Manage this page')->assertDontSee('Acme Holdings');
    }

    public function test_earned_reviews_show_on_the_page_only_once_revealed_and_approved(): void
    {
        $owner = $this->infodotPerson();
        $page = $this->page($owner, ['name' => 'Moyo Books']);
        $a = $this->person();
        $b = $this->person();
        $make = fn ($user, $body, array $extra = []) => \App\Models\PulseReview::create(array_merge([
            'user_id' => $user->id, 'reviewable_type' => $page->getMorphClass(), 'reviewable_id' => $page->id, 'rating' => 5, 'body' => $body,
            'is_verified' => true, 'reviewer_side' => 'initiator', 'outcome' => 'won', 'pseudonymous' => false, 'moderation' => 'approved',
        ], $extra));

        $make($a, 'Revealed and clean review', ['revealed_at' => now(), 'conversation_id' => null]);
        $make($b, 'Still blind review', ['revealed_at' => null]);
        $make($this->person(), 'Held review', ['revealed_at' => now(), 'moderation' => 'held']);

        $this->get(route('entities.show', ['entity' => $page->slug]))->assertOk()
            ->assertSee('Revealed and clean review')->assertSee('1 review')->assertSee('5 out of 5')
            ->assertSee('written after both sides confirmed a result')
            ->assertDontSee('Still blind review')->assertDontSee('Held review');

        $this->actingAs($this->person())->get(route('entities.show', ['entity' => $page->slug]))->assertOk()->assertSee('Revealed and clean review');
    }

    public function test_a_guest_gets_a_404_for_a_page_that_is_not_live_and_for_a_held_page(): void
    {
        $draft = $this->page(null, ['status' => 'archived']);
        $held = $this->page(null, ['moderation' => 'pending']);

        $this->get(route('entities.show', ['entity' => $draft->slug]))->assertNotFound();
        $this->get(route('entities.show', ['entity' => $held->slug]))->assertNotFound();
    }

    public function test_a_stranger_cannot_see_an_unpublished_page_but_a_member_can(): void
    {
        $page = $this->page(null, ['status' => 'archived']);
        $stranger = $this->person();

        $this->actingAs($stranger)->get(route('entities.show', ['entity' => $page->slug]))->assertNotFound();

        $owner = $page->members()->first()->user;
        $this->actingAs($owner)->get(route('entities.show', ['entity' => $page->slug]))->assertOk()->assertSee('off the floor', false);
    }

    public function test_a_member_sees_the_full_page_with_knock_and_actions(): void
    {
        $page = $this->page(null, ['name' => 'Moyo Books', 'public_email' => 'hello@moyobooks.test', 'website' => 'https://moyobooks.test']);
        PulseOffer::factory()->create(['entity_id' => $page->id, 'created_by' => $page->created_by, 'title' => 'Payroll for ten staff', 'slug' => 'payroll', 'summary' => 'Weekly or monthly runs.']);
        $visitor = $this->person();

        $this->actingAs($visitor)->get(route('entities.show', ['entity' => $page->slug]))
            ->assertOk()
            ->assertSee('Moyo Books')->assertSee('Weekly or monthly runs.')
            ->assertSee('hello@moyobooks.test')->assertSee('(claimed)')
            ->assertSeeLivewire('pulse.knock-sheet')
            ->assertSeeLivewire('pulse.business.listing-actions')
            ->assertDontSee('Manage this page')
            ->assertDontSee($page->members()->first()->user->email);
    }

    public function test_the_ladder_never_says_verified_business_and_labels_claims(): void
    {
        $page = $this->page(null, ['website' => 'https://moyobooks.test', 'registration_number' => '2019/123456/07']);
        $visitor = $this->person();

        foreach ([null, $visitor] as $viewer) {
            $html = $viewer ? $this->actingAs($viewer)->get(route('entities.show', ['entity' => $page->slug]))->getContent() : $this->get(route('entities.show', ['entity' => $page->slug]))->getContent();

            $this->assertStringNotContainsStringIgnoringCase('verified business', $html);
            $this->assertStringContainsString('Claimed by them, not checked', $html);
            $this->assertStringContainsString('What Pulse can show', $html);
        }
    }

    public function test_stats_are_hidden_without_data_and_shown_from_real_lines(): void
    {
        $page = $this->page();
        $visitor = $this->person();

        $this->actingAs($visitor)->get(route('entities.show', ['entity' => $page->slug]))
            ->assertDontSee('How they answer')->assertDontSee('Results both sides confirmed');
    }

    public function test_an_owner_sees_the_manage_button_and_a_held_notice(): void
    {
        $page = $this->page(null, ['moderation' => 'pending']);
        $owner = $page->members()->first()->user;

        $this->actingAs($owner)->get(route('entities.show', ['entity' => $page->slug]))
            ->assertOk()->assertSee('Manage this page')->assertSee('Waiting for a human')->assertDontSee('Knock');
    }
}
