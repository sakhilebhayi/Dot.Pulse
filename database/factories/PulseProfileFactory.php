<?php

namespace Database\Factories;

use App\Models\PulseProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PulseProfile>
 */
class PulseProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role' => 'customer',
            'community_points' => 0,
            'headline' => 'Bookkeeper and payroll specialist',
            'bio' => fake()->paragraph(),
            'skills' => ['bookkeeping', 'payroll', 'excel'],
            'kind' => 'professional',
            'discoverability' => 'off',
            'reach' => 'region',
            'show_place' => true,
            'experience_years' => 6,
            'contact_policy' => 'context',
            'moderation' => 'approved',
        ];
    }

    public function discoverable(string $mode = 'open'): static
    {
        return $this->state([
            'discoverability' => $mode,
            'age_confirmed_at' => now(),
            'onboarded_at' => now(),
        ]);
    }

    public function anonymous(): static
    {
        return $this->discoverable('anonymous');
    }

    public function ageConfirmed(): static
    {
        return $this->state(['age_confirmed_at' => now()]);
    }
}
