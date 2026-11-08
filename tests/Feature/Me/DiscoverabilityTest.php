<?php

namespace Tests\Feature\Me;

use App\Livewire\Pulse\Me\Discoverability;
use App\Livewire\Pulse\Me\Onboarding;
use App\Models\PulseProfile;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Entities\ProfileService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class DiscoverabilityTest extends MeTestCase
{
    private function ready(array $over = []): User
    {
        return $this->member($over + ['headline' => 'Bookkeeper and payroll specialist', 'skills' => json_encode(['bookkeeping', 'payroll']), 'experience_years' => 6]);
    }

    public function test_the_default_is_off_and_the_page_renders(): void
    {
        $u = $this->ready();

        $this->actingAs($u)->get('/me/discoverability')->assertOk()->assertSee('What an employer sees in anonymous mode');
        Livewire::actingAs($u)->test(Discoverability::class)->assertSet('mode', 'off');
    }

    public function test_choosing_a_mode_saves_it_immediately_through_the_service(): void
    {
        $u = $this->ready();

        Livewire::actingAs($u)->test(Discoverability::class)
            ->set('mode', 'anonymous')->assertHasNoErrors()->assertDispatched('toast');
        $this->assertSame('anonymous', $this->profileOf($u)->discoverability);

        Livewire::actingAs($u)->test(Discoverability::class)->assertSet('mode', 'anonymous')->set('mode', 'off');
        $this->assertSame('off', $this->profileOf($u)->discoverability);
    }

    public function test_being_found_needs_a_headline_and_a_skill_and_the_choice_snaps_back(): void
    {
        $u = $this->member(['headline' => null, 'skills' => null]);

        $c = Livewire::actingAs($u)->test(Discoverability::class)->set('mode', 'open');

        $c->assertSet('mode', 'off');
        $this->assertNotNull($c->get('problem'));
        $this->assertSame('off', $this->profileOf($u)->discoverability);
    }

    public function test_nobody_is_discoverable_without_the_age_declaration(): void
    {
        $u = $this->ready();
        DB::table('pulse_profiles')->where('user_id', $u->id)->update(['age_confirmed_at' => null]);

        Livewire::actingAs($u)->test(Discoverability::class)->set('mode', 'anonymous')->assertSet('mode', 'off');
        $this->assertSame('off', $this->profileOf($u)->discoverability);
    }

    public function test_the_preview_is_the_anonymous_card_and_leaks_nothing_personal(): void
    {
        $g = $this->dGeo();
        $u = $this->ready(['place_id' => $g->sandton->id, 'show_place' => true]);
        $u->forceFill(['name' => 'Thandiwe Mokoena', 'email' => 'thandi.mokoena@example.test'])->save();
        DB::table('pulse_profiles')->where('user_id', $u->id)->update(['bio' => 'Call me on 082 555 1234', 'headline' => 'Thandiwe Mokoena, bookkeeper', 'work_history' => json_encode([['title' => 'Accountant', 'org' => 'Acme Holdings']])]);

        $c = Livewire::actingAs($u)->test(Discoverability::class);
        $card = $c->instance()->preview();

        $this->assertTrue($card['anonymous']);
        $this->assertArrayNotHasKey('name', $card);
        $this->assertArrayNotHasKey('user_id', $card);
        $this->assertArrayNotHasKey('avatar_url', $card);
        $this->assertArrayNotHasKey('bio', $card);
        $this->assertArrayNotHasKey('work_history', $card);
        $this->assertSame('Gauteng', $card['region'], 'a region, never the suburb');

        $this->assertSame(1, preg_match('/aria-labelledby="disc-preview">(.*?)<\/section>/s', $c->html(), $m));
        $html = $m[1];
        foreach (['Thandiwe', 'Mokoena', 'thandi.mokoena', '082 555', 'Acme Holdings', 'Sandton'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "the preview must not contain {$needle}");
        }
        $this->assertStringContainsString('An anonymous professional', $html);
        $this->assertStringContainsString('5 to 9 years', $html);
        $this->assertSame(['bookkeeping', 'payroll'], $card['skills'], 'arrays, not encoded strings');
        $this->assertStringNotContainsString('[&quot;', $html);
        $this->assertStringNotContainsString('["', $html);
    }

    public function test_open_to_is_saved_and_validated(): void
    {
        $u = $this->ready();

        Livewire::actingAs($u)->test(Discoverability::class)->set('openTo', ['work', 'collab']);
        $this->assertSame(['work', 'collab'], $this->profileOf($u)->open_to);

        $c = Livewire::actingAs($u)->test(Discoverability::class)->set('openTo', ['work', 'time travel']);
        $this->assertNotNull($c->get('problem'));
        $this->assertSame(['work', 'collab'], $this->profileOf($u)->fresh()->open_to);
    }

    public function test_hiding_from_the_current_employer_toggles_the_flag_the_matcher_reads(): void
    {
        $u = $this->ready();
        DB::table('pulse_profiles')->where('user_id', $u->id)->update(['work_history' => json_encode([
            ['title' => 'Accountant', 'org' => 'Acme Holdings', 'current' => true],
            ['title' => 'Clerk', 'org' => 'Old Co', 'to_year' => 2018],
        ])]);

        $c = Livewire::actingAs($u)->test(Discoverability::class)->assertSee('Do not show me to Acme Holdings')->assertDontSee('Do not show me to Old Co');
        $c->call('toggleHide', 0);

        $job = $this->profileOf($u)->work_history[0];
        $this->assertTrue($job['hide_from_employer']);
        $this->assertSame('Acme Holdings', $job['employer']);

        $c->call('toggleHide', 0);
        $this->assertFalse($this->profileOf($u)->work_history[0]['hide_from_employer']);
        $c->call('toggleHide', 7); // out of range: nothing happens
    }

    public function test_the_empty_state_points_to_the_profile_when_there_is_no_current_job(): void
    {
        $u = $this->ready();

        Livewire::actingAs($u)->test(Discoverability::class)->assertSee('No current job listed.');
    }

    public function test_the_cohort_minimum_is_explained(): void
    {
        Livewire::actingAs($this->ready())->test(Discoverability::class)->assertSee('at least 5');
    }

    public function test_turning_it_off_takes_the_talent_card_off_the_floor(): void
    {
        $u = $this->ready();
        $c = Livewire::actingAs($u)->test(Discoverability::class)->set('mode', 'anonymous');

        $this->assertNotNull(PulseSignal::query()->where('owner_id', $u->id)->where('kind', 'talent')->first());
        $c->set('mode', 'off');

        $live = PulseSignal::query()->where('owner_id', $u->id)->where('kind', 'talent')->where('status', 'live')->exists();
        $this->assertFalse($live, 'withdrawn means not live');
    }

    public function test_suppliers_and_learning_are_choices_with_labels_everywhere_open_to_is_shown(): void
    {
        $this->assertContains('suppliers', PulseProfile::OPEN_TO);
        $this->assertContains('learning', PulseProfile::OPEN_TO);
        $this->assertEqualsCanonicalizing(PulseProfile::OPEN_TO, array_keys(Onboarding::OPEN_TO_LABELS), 'every choice has a label');

        $u = $this->member();
        app(ProfileService::class)->setOpenTo($u, ['suppliers', 'learning']);
        $this->assertSame(['suppliers', 'learning'], $this->profileOf($u)->open_to);
    }
}
