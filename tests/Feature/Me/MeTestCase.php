<?php

namespace Tests\Feature\Me;

use App\Models\PulseProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DiscoveryFixtures;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

/** Shared fixtures for the People (Me, onboarding, profile page) tests. */
abstract class MeTestCase extends TestCase
{
    use DiscoveryFixtures;
    use LinesFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No moderation rules installed: profile text is accepted, as in the Entities tests.
        config(['pulse.entities.rules_class' => 'Tests\\Absent\\Rules']);
    }

    /** A person who has not been through the welcome flow: no age declaration, not onboarded. */
    protected function newcomer(): User
    {
        $u = User::factory()->create();
        PulseProfile::query()->create(['user_id' => $u->id, 'role' => 'customer']);

        return $u->fresh();
    }

    /** An adult who finished onboarding. */
    protected function member(array $profile = []): User
    {
        $u = $this->person($profile);
        DB::table('pulse_profiles')->where('user_id', $u->id)->update(['onboarded_at' => now()->subDay()]);

        return $u->fresh();
    }

    protected function profileOf(User $u): PulseProfile
    {
        return PulseProfile::query()->where('user_id', $u->id)->firstOrFail();
    }
}
