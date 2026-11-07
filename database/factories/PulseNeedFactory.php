<?php

namespace Database\Factories;

use App\Models\PulseNeed;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PulseNeed>
 */
class PulseNeedFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => 'need',
            'title' => 'Looking for '.fake()->words(3, true),
            'details' => fake()->paragraph(),
            'tags' => ['bookkeeping'],
            'budget_text' => 'Up to R3 000',
            'reach' => 'region',
            'visibility' => 'open',
            'status' => 'open',
            'moderation' => 'approved',
            'published_at' => now(),
            'expires_at' => now()->addDays(30),
        ];
    }

    public function closed(): static
    {
        return $this->state(['status' => 'closed']);
    }

    public function pendingModeration(): static
    {
        return $this->state(['moderation' => 'pending']);
    }
}
