<?php

namespace Database\Factories;

use App\Models\PulsePost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PulsePost>
 */
class PulsePostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'community_id' => null,
            'team_id' => null,
            'type' => 'discussion',
            'title' => null,
            'body' => fake()->sentence(12),
            'status' => 'published',
        ];
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
