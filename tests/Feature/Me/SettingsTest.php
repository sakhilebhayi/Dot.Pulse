<?php

namespace Tests\Feature\Me;

use App\Livewire\Pulse\Me\Settings;
use Livewire\Livewire;

class SettingsTest extends MeTestCase
{
    public function test_the_page_renders_for_a_member_and_redirects_a_newcomer(): void
    {
        $this->actingAs($this->member())->get('/me/settings')->assertOk()->assertSee('Who can message you first');
        $this->actingAs($this->newcomer())->get('/me/settings')->assertRedirect(route('onboarding'));
    }

    public function test_the_contact_policy_is_saved_when_picked(): void
    {
        $u = $this->member();

        Livewire::actingAs($u)->test(Settings::class)->assertSet('contactPolicy', 'context')->set('contactPolicy', 'nobody')->assertDispatched('toast');
        $this->assertSame('nobody', $this->profileOf($u)->contact_policy);
    }

    public function test_an_unknown_contact_policy_is_refused_and_nothing_changes(): void
    {
        $u = $this->member();

        $c = Livewire::actingAs($u)->test(Settings::class)->set('contactPolicy', 'everyone');

        $this->assertNotNull($c->get('problem'));
        $this->assertSame('context', $this->profileOf($u)->contact_policy);
    }

    public function test_a_place_is_picked_from_the_gazetteer_and_can_be_removed(): void
    {
        $g = $this->dGeo();
        $u = $this->member();

        $c = Livewire::actingAs($u)->test(Settings::class)
            ->set('placeQuery', 'Pret')
            ->assertSee('Pretoria')
            ->call('pickPlace', $g->pta->id)
            ->assertSet('placeId', $g->pta->id);
        $this->assertSame($g->pta->id, $this->profileOf($u)->place_id);

        $c->call('clearPlace');
        $this->assertNull($this->profileOf($u)->place_id);
    }

    public function test_a_made_up_place_id_is_ignored(): void
    {
        $u = $this->member();

        Livewire::actingAs($u)->test(Settings::class)->call('pickPlace', 987654)->assertSet('placeId', null);
        $this->assertNull($this->profileOf($u)->place_id);
    }

    public function test_display_of_place_and_reach_are_saved(): void
    {
        $u = $this->member();

        Livewire::actingAs($u)->test(Settings::class)->set('showPlace', false)->set('reach', 'country');
        $p = $this->profileOf($u);
        $this->assertFalse((bool) $p->show_place);
        $this->assertSame('country', $p->reach);

        $c = Livewire::actingAs($u)->test(Settings::class)->set('reach', 'galaxy');
        $this->assertNotNull($c->get('problem'));
        $this->assertSame('country', $this->profileOf($u)->reach);
    }

    public function test_the_page_explains_that_the_place_is_approximate(): void
    {
        Livewire::actingAs($this->member())->test(Settings::class)->assertSee('Approximate on purpose')->assertSee('never where you are standing');
    }
}
