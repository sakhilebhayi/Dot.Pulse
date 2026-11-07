<?php

namespace Database\Factories;

use App\Models\PulseEntity;
use App\Models\PulseOffer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PulseOffer>
 */
class PulseOfferFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'entity_id' => PulseEntity::factory(),
            'created_by' => User::factory(),
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'kind' => 'service',
            'title' => ucfirst($title),
            'summary' => fake()->sentence(10),
            'description' => fake()->paragraph(),
            'category' => 'bookkeeping',
            'tags' => ['accounting', 'tax'],
            'price_mode' => 'from',
            'amount_cents' => 50000,
            'currency' => 'ZAR',
            'unit' => 'month',
            'availability' => 'available',
            'accepts_quotes' => true,
            'status' => 'live',
            'moderation' => 'approved',
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft', 'published_at' => null]);
    }

    public function paused(): static
    {
        return $this->state(['status' => 'paused']);
    }

    public function pendingModeration(): static
    {
        return $this->state(['moderation' => 'pending']);
    }
}
