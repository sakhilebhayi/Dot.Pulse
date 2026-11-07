<?php

namespace Tests;

use App\Models\PulseProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // The production rule shows an anonymous talent card only inside a cohort of five (D23). Most tests seed one
        // professional to check something else, so the floor is lowered here; the tests for the rule itself put it back.
        config(['pulse.privacy.talent_cohort_min' => 1]);
    }

    /** Mark a person as having finished the welcome flow, so signed-in pages open for them. */
    protected function onboard(User $user): User
    {
        PulseProfile::query()->updateOrCreate(['user_id' => $user->id], ['onboarded_at' => now()->subDay()]);

        return $user;
    }
}
