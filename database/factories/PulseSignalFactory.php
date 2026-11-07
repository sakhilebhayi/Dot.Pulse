<?php

namespace Database\Factories;

use App\Models\PulseSignal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare signal row, for tests of consumers (matching, search). Rows for real subjects come from the SignalIndexer.
 *
 * @extends Factory<PulseSignal>
 */
class PulseSignalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kind' => 'offer',
            'subject_type' => 'offer',
            'subject_id' => fake()->unique()->numberBetween(1_000_000, 9_000_000),
            'title' => fake()->sentence(4),
            'excerpt' => fake()->sentence(12),
            'topics' => ['bookkeeping'],
            'skills' => [],
            'reach' => 'region',
            'is_remote' => false,
            'anonymous' => false,
            'quality' => 50,
            'status' => 'live',
            'published_at' => now(),
        ];
    }

    public function kind(string $kind): static
    {
        return $this->state(['kind' => $kind]);
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
