<?php

namespace Tests\Feature\Me;

use App\Livewire\Pulse\Me\Onboarding;
use App\Models\PulseNotificationPref;
use Livewire\Livewire;

class OnboardingTest extends MeTestCase
{
    private function walkTo(\Livewire\Features\SupportTesting\Testable $c, int $step): \Livewire\Features\SupportTesting\Testable
    {
        while ($c->get('step') < $step) {
            $c->call('next');
        }

        return $c;
    }

    public function test_it_starts_on_the_age_gate_and_cannot_go_on_without_it(): void
    {
        $u = $this->newcomer();

        Livewire::actingAs($u)->test(Onboarding::class)
            ->assertSet('step', 1)
            ->assertSee('18 or over')
            ->call('next')
            ->assertHasErrors(['adult'])
            ->assertSet('step', 1);

        $this->assertNull($this->profileOf($u)->age_confirmed_at, 'nothing is declared for the person');
    }

    public function test_ticking_the_box_records_the_declaration_and_moves_on(): void
    {
        $u = $this->newcomer();

        Livewire::actingAs($u)->test(Onboarding::class)
            ->set('adult', true)
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 2);

        $this->assertNotNull($this->profileOf($u)->age_confirmed_at);
    }

    public function test_the_age_step_cannot_be_skipped_around(): void
    {
        $u = $this->newcomer();

        $c = Livewire::actingAs($u)->test(Onboarding::class)->call('skip');
        // skip on step one moves nothing into the profile and never completes onboarding
        $this->assertNull($this->profileOf($u)->onboarded_at);
        $this->assertNull($this->profileOf($u)->age_confirmed_at);

        // Finishing directly (forged call) still needs the declaration.
        $c->call('finish');
        $this->assertNull($this->profileOf($u)->onboarded_at, 'no onboarded_at without being asked and confirmed');
    }

    public function test_every_privacy_choice_defaults_to_the_private_option(): void
    {
        $u = $this->newcomer();

        Livewire::actingAs($u)->test(Onboarding::class)
            ->assertSet('discoverability', 'off')
            ->assertSet('contactPolicy', 'context')
            ->assertSet('showPlace', false)
            ->assertSet('digest', false)
            ->assertSet('emailMatches', false)
            ->assertSet('kind', 'individual');
    }

    public function test_skipping_every_step_after_the_age_gate_finishes_with_the_private_defaults(): void
    {
        $u = $this->newcomer();

        $c = Livewire::actingAs($u)->test(Onboarding::class)->set('adult', true)->call('next');
        for ($i = 2; $i <= 7; $i++) {
            $c->call('skip');
        }

        $c->assertRedirect();
        $p = $this->profileOf($u);
        $this->assertNotNull($p->onboarded_at);
        $this->assertSame('off', $p->discoverability);
        $this->assertSame('context', $p->contact_policy);
        $this->assertFalse((bool) $p->show_place);

        $pref = PulseNotificationPref::query()->where('user_id', $u->id)->firstOrFail();
        $this->assertFalse($pref->digest, 'the daily summary is opt-in');
        $this->assertFalse($pref->categories['matches']['email']);
        $this->assertTrue($pref->categories['lines']['email']);
    }

    public function test_a_full_run_saves_what_was_chosen_through_the_services(): void
    {
        $g = $this->dGeo();
        $u = $this->newcomer();

        $c = Livewire::actingAs($u)->test(Onboarding::class)
            ->set('adult', true)->call('next')
            ->set('kind', 'professional')->set('openTo', ['work', 'mentoring'])->call('next')
            ->call('pickPlace', $g->jhb->id)->set('showPlace', true)->call('next')
            ->call('addTag', 'skills', 'Bookkeeping')->call('addTag', 'skills', 'bookkeeping')->call('addTag', 'interests', 'Cycling')->call('next')
            ->set('discoverability', 'anonymous')->set('headline', 'Bookkeeper and payroll specialist')->call('next')
            ->set('contactPolicy', 'requests')->call('next')
            ->set('emailConversations', false)->set('digest', true)->set('digestHour', '06:00')->call('next');

        $c->assertRedirect();
        $p = $this->profileOf($u);
        $this->assertSame('professional', $p->kind);
        $this->assertSame(['work', 'mentoring'], $p->open_to);
        $this->assertSame($g->jhb->id, $p->place_id);
        $this->assertTrue((bool) $p->show_place);
        $this->assertSame(['Bookkeeping'], $p->skills, 'a duplicate chip is ignored');
        $this->assertSame(['Cycling'], $p->interests);
        $this->assertSame('anonymous', $p->discoverability);
        $this->assertSame('requests', $p->contact_policy);
        $this->assertNotNull($p->onboarded_at);

        $pref = PulseNotificationPref::query()->where('user_id', $u->id)->firstOrFail();
        $this->assertTrue($pref->digest);
        $this->assertSame('06:00', $pref->digest_hour);
        $this->assertFalse($pref->categories['lines']['email']);
        $this->assertTrue($pref->categories['system']['email'], 'safety mail is not optional');
    }

    public function test_choosing_to_be_found_needs_a_headline_and_a_skill(): void
    {
        $u = $this->newcomer();

        $c = Livewire::actingAs($u)->test(Onboarding::class)->set('adult', true)->call('next');
        $this->walkTo($c, 5);
        $c->set('discoverability', 'open')->call('next');

        $this->assertSame(5, $c->get('step'), 'stays on the discoverability step');
        $this->assertNotNull($c->get('problem'));
        $this->assertSame('off', $this->profileOf($u)->discoverability);
        $this->assertNull($this->profileOf($u)->onboarded_at);
    }

    public function test_back_goes_back_and_the_last_step_says_finish(): void
    {
        $u = $this->newcomer();

        $c = Livewire::actingAs($u)->test(Onboarding::class)->set('adult', true)->call('next')->call('next')->assertSet('step', 3);
        $c->call('back')->assertSet('step', 2);
        $this->walkTo($c, 7);
        $c->assertSee('Finish and go to the Floor');
    }

    public function test_a_finished_person_is_sent_to_their_profile_not_through_it_again(): void
    {
        $u = $this->member();

        Livewire::actingAs($u)->test(Onboarding::class)->assertRedirect(route('me.profile'));
    }

    public function test_the_time_zone_is_taken_from_the_device_only_when_valid(): void
    {
        $u = $this->newcomer();

        Livewire::actingAs($u)->test(Onboarding::class)
            ->call('setTimezone', 'Mars/Olympus')->assertSet('timezone', 'Africa/Johannesburg')
            ->call('setTimezone', 'Europe/London')->assertSet('timezone', 'Europe/London');
    }

    public function test_the_page_renders_and_a_guest_is_sent_to_login(): void
    {
        $this->get('/welcome-in')->assertRedirect('/login');
        $this->actingAs($this->newcomer())->get('/welcome-in')->assertOk()->assertSee('Pulse is for people who are 18 or over.');
    }
}
