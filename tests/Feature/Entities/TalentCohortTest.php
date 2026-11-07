<?php

namespace Tests\Feature\Entities;

use App\Livewire\Pulse\Business\RoleManager;
use App\Models\PulseEntity;
use App\Models\PulseProfile;
use App\Models\User;
use App\Services\Pulse\Entities\ProfileService;
use App\Services\Pulse\Entities\SignalIndexer;
use App\Services\Pulse\Entities\TalentCohort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\DiscoveryFixtures;
use Tests\TestCase;

class TalentCohortTest extends TestCase
{
    use DiscoveryFixtures;
    use RefreshDatabase;

    private object $geo;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pulse.entities.rules_class' => 'Tests\\Absent\\Rules']);
        $this->geo = $this->dGeo();
    }

    private function pro(array $job = [], string $mode = 'anonymous', bool $adult = true, $place = null): User
    {
        $place ??= $this->geo->jhb;
        $u = $this->dUser(['place' => $place]);
        $service = app(ProfileService::class);

        if ($adult) {
            $service->confirmAge($u);
        }

        $service->update($u, ['headline' => 'Welder', 'skills' => ['welding'], 'place_id' => $place->id, 'experience_years' => 5, 'work_history' => $job ? [$job] : []]);

        if ($adult) {
            $service->setDiscoverability($u, $mode);
        } else {
            DB::table('pulse_profiles')->where('user_id', $u->id)->update(['discoverability' => $mode]);
        }

        app(SignalIndexer::class)->syncSubject(PulseProfile::where('user_id', $u->id)->first());

        return $u;
    }

    private function employer(): PulseEntity
    {
        return $this->dEntity($this->dUser(), ['name' => 'Acme Steel', 'is_employer' => true, 'place_id' => $this->geo->jhb->id, 'hiring_signal' => 'active', 'hiring_skills' => ['Welding']]);
    }

    private function cohort(): TalentCohort
    {
        return app(TalentCohort::class);
    }

    public function test_nothing_is_said_below_five_and_then_only_a_band(): void
    {
        $employer = $this->employer();

        foreach ([0, 1, 4] as $expected) {
            while (DB::table('pulse_signals')->where('kind', 'talent')->where('status', 'live')->count() < $expected) {
                $this->pro();
            }
            $this->assertNull($this->cohort()->countFor($employer), "{$expected} match: no number");
        }

        $this->pro();
        $this->assertSame(5, $this->cohort()->countFor($employer));

        foreach (range(1, 4) as $i) {
            $this->pro();
        }
        $this->assertSame(5, $this->cohort()->countFor($employer), '9 shows as the 5 band');

        $this->pro();
        $this->assertSame(10, $this->cohort()->countFor($employer));

        foreach (range(1, 15) as $i) {
            $this->pro();
        }
        $this->assertSame(25, $this->cohort()->countFor($employer), '25 and over is one band');

        $this->assertSame([null, 5, 5, 10, 10, 25], array_map(fn ($n) => TalentCohort::band($n), [4, 5, 9, 10, 24, 25]));
        $this->assertSame(['5 to 9', '10 to 24', '25 or more'], array_map(TalentCohort::label(...), [5, 10, 25]));
    }

    public function test_people_who_hide_from_this_employer_or_are_not_confirmed_adults_are_never_counted(): void
    {
        $employer = $this->employer();

        foreach (range(1, 5) as $i) {
            $this->pro();
        }
        $this->assertSame(5, $this->cohort()->countFor($employer));

        $this->pro(['title' => 'Welder', 'employer' => 'ACME Steel', 'hide_from_employer' => true, 'current' => true]);
        $this->pro(['title' => 'Welder', 'org' => 'Acme Steel', 'hide_from_employer' => true]);
        $this->assertSame(5, $this->cohort()->countFor($employer), 'two who hide from this employer are left out');

        // Hiding from a different employer does not matter here.
        $this->pro(['title' => 'Welder', 'employer' => 'Someone Else', 'hide_from_employer' => true]);
        $this->assertSame(5, $this->cohort()->countFor($employer));

        // A band edge: four visible plus the hider is still fewer than five to this employer.
        DB::table('pulse_profiles')->whereIn('user_id', DB::table('pulse_signals')->where('kind', 'talent')->pluck('owner_id')->take(2))->update(['age_confirmed_at' => null]);
        $this->assertNull($this->cohort()->countFor($employer), 'unconfirmed ages are not counted');
    }

    public function test_discoverability_off_other_places_and_a_blocked_page_are_left_out_and_lists_work_too(): void
    {
        $employer = $this->employer();
        $users = collect(range(1, 6))->map(fn () => $this->pro());
        $this->assertSame(5, $this->cohort()->countFor($employer));

        DB::table('pulse_profiles')->where('user_id', $users[0]->id)->update(['discoverability' => 'off']);
        $this->assertSame(5, $this->cohort()->countFor(['welding'], $this->geo->jhb), 'five left');

        DB::table('pulse_entity_blocks')->insert(['entity_id' => $employer->id, 'user_id' => $users[1]->id, 'side' => 'user', 'created_by' => $users[1]->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertNull($this->cohort()->countFor($employer), 'one blocked the page, so four');
        $this->assertSame(5, $this->cohort()->countFor(['welding'], $this->geo->jhb), 'a plain list has no employer to exclude for');

        $this->assertNull($this->cohort()->countFor(['welding'], $this->geo->lagos), 'nobody is near Lagos');
        $this->assertNull($this->cohort()->countFor(['plumbing'], $this->geo->jhb), 'no shared skill');
        $this->assertNull($this->cohort()->countFor([], null));
    }

    public function test_the_roles_tab_shows_the_band_only_when_there_is_one(): void
    {
        $employer = $this->employer();
        $owner = $employer->members()->first()->user;
        DB::table('pulse_profiles')->updateOrInsert(['user_id' => $owner->id], ['updated_at' => now(), 'created_at' => now()]);

        foreach (range(1, 4) as $i) {
            $this->pro();
        }
        Livewire::actingAs($owner)->test(RoleManager::class, ['entity' => $employer])->assertDontSee('professionals match.');

        $this->pro();
        Livewire::actingAs($owner)->test(RoleManager::class, ['entity' => $employer])->assertSee('5 to 9 professionals match.');
    }
}
