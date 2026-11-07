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
    }

    /** Mark a person as having finished the welcome flow, so signed-in pages open for them. */
    protected function onboard(User $user): User
    {
        PulseProfile::query()->updateOrCreate(['user_id' => $user->id], ['onboarded_at' => now()->subDay()]);

        return $user;
    }
}
