<?php

namespace Tests\Feature\Floor;

class RoutesTest extends FloorTestCase
{
    public function test_every_floor_page_needs_a_signed_in_person(): void
    {
        foreach (['/floor', '/trade', '/work', '/local', '/learn', '/search', '/saved', '/door'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }

        [$signal] = $this->offerFor();
        $this->get(route('signals.show', $signal))->assertRedirect('/login');
    }

    public function test_the_floor_pages_render_for_a_signed_in_person(): void
    {
        $this->actingAs($this->me);
        $this->offerFor();

        foreach (['floor', 'trade', 'work', 'local', 'learn', 'search', 'saved', 'door'] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_the_dashboard_and_the_home_page_send_a_signed_in_person_to_the_floor(): void
    {
        $this->get('/')->assertOk();

        $this->actingAs($this->me);
        $this->get('/dashboard')->assertRedirect(route('floor'));
        $this->get('/')->assertRedirect(route('floor'));
        $this->get('/community')->assertOk()->assertViewIs('dashboard');
    }

    public function test_the_four_mode_routes_open_the_floor_with_the_mode_chosen(): void
    {
        $this->actingAs($this->me);

        foreach (['trade', 'work', 'local', 'learn'] as $mode) {
            $this->get(route($mode))->assertOk()->assertSee('aria-current="page"', false);
        }
    }
}
