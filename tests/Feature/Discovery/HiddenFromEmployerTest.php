<?php

namespace Tests\Feature\Discovery;

use App\Models\PulseMatch;
use App\Models\PulseProfile;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\Matcher;
use App\Services\Pulse\Entities\ProfileService;
use App\Services\Pulse\Entities\SignalIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DiscoveryFixtures;
use Tests\TestCase;

class HiddenFromEmployerTest extends TestCase
{
    use DiscoveryFixtures;
    use RefreshDatabase;

    private function talent(array $job): array
    {
        $geo = $this->dGeo();
        $pro = $this->dUser(['place' => $geo->jhb]);
        $service = app(ProfileService::class);
        $service->confirmAge($pro);
        $service->update($pro, [
            'headline' => 'Welder', 'skills' => ['welding'], 'place_id' => $geo->jhb->id, 'experience_years' => 6,
            'work_history' => [$job],
        ]);
        $service->setDiscoverability($pro, 'anonymous');
        $profile = PulseProfile::where('user_id', $pro->id)->first();
        app(SignalIndexer::class)->syncSubject($profile);

        return [$pro, $profile, PulseSignal::forSubject('profile', $profile->id)->first(), $geo];
    }

    private function employerOf(string $name, $geo): User
    {
        $employer = $this->dUser(['place' => $geo->jhb]);
        $entity = $this->dEntity($employer, ['name' => $name, 'is_employer' => true]);
        $this->dRole($entity, ['skills' => ['welding']]);

        return $employer;
    }

    private function sees(User $employer, PulseSignal $signal): bool
    {
        app(Matcher::class)->forUser($employer);

        return PulseMatch::where('user_id', $employer->id)->where('signal_id', $signal->id)->exists();
    }

    public function test_a_job_hidden_from_its_employer_keeps_the_profile_away_from_that_employer_only(): void
    {
        [, $profile, $signal, $geo] = $this->talent(['title' => 'Welder', 'org' => 'ACME Steel', 'current' => true, 'hide_from_employer' => true]);

        $row = $profile->fresh()->work_history[0];
        $this->assertSame('ACME Steel', $row['employer']);
        $this->assertSame('ACME Steel', $row['org']);
        $this->assertTrue($row['hide_from_employer']);

        $this->assertFalse($this->sees($this->employerOf('Acme Steel', $geo), $signal), 'hidden from the named employer');
        $this->assertTrue($this->sees($this->employerOf('Bolt Works', $geo), $signal), 'still found by other employers');
    }

    public function test_the_editor_style_org_only_row_without_a_flag_stays_visible_to_its_employer(): void
    {
        [, , $signal, $geo] = $this->talent(['title' => 'Welder', 'org' => 'ACME Steel', 'current' => true]);

        $this->assertTrue($this->sees($this->employerOf('Acme Steel', $geo), $signal));
    }

    public function test_the_matcher_still_reads_org_when_a_row_has_no_employer_key(): void
    {
        [, $profile, $signal, $geo] = $this->talent(['title' => 'Welder', 'org' => 'ACME Steel', 'current' => true]);
        DB::table('pulse_profiles')->where('id', $profile->id)->update(['work_history' => json_encode([['title' => 'Welder', 'org' => 'ACME Steel', 'hide_from_employer' => true]])]);

        $this->assertFalse($this->sees($this->employerOf('Acme Steel', $geo), $signal));
    }

    public function test_the_flag_needs_an_employer_name_and_never_leaks_in_a_card(): void
    {
        [$pro, $profile] = $this->talent(['title' => 'Welder', 'org' => '', 'hide_from_employer' => true]);
        $this->assertFalse($profile->fresh()->work_history[0]['hide_from_employer']);

        app(ProfileService::class)->update($pro, ['work_history' => [['title' => 'Welder', 'employer' => 'Acme', 'hide_from_employer' => true]]]);
        app(ProfileService::class)->setDiscoverability($pro, 'open');
        $profile = $profile->fresh();
        $viewer = User::factory()->create();

        $seen = app(ProfileService::class)->card($profile, $viewer);
        $this->assertSame('Acme', $seen['work_history'][0]['employer']);
        $this->assertArrayNotHasKey('hide_from_employer', $seen['work_history'][0]);
        $this->assertTrue(app(ProfileService::class)->card($profile, $pro)['work_history'][0]['hide_from_employer']);
    }
}
