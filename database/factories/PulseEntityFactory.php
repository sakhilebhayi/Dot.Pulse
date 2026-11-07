<?php

namespace Database\Factories;

use App\Models\PulseEntity;
use App\Models\PulseEntityMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PulseEntity>
 */
class PulseEntityFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'name' => $name,
            'tagline' => fake()->sentence(6),
            'about' => fake()->paragraph(),
            'is_seller' => true,
            'is_employer' => false,
            'status' => 'live',
            'moderation' => 'approved',
            'contact_policy' => 'anyone',
            'hiring_signal' => 'none',
            'created_by' => User::factory(),
        ];
    }

    public function employer(): static
    {
        return $this->state(['is_employer' => true]);
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft']);
    }

    public function pendingModeration(): static
    {
        return $this->state(['moderation' => 'pending']);
    }

    /** Adds the creator as owner after creation. */
    public function withOwner(): static
    {
        return $this->afterCreating(function (PulseEntity $entity) {
            PulseEntityMember::firstOrCreate(
                ['entity_id' => $entity->id, 'user_id' => $entity->created_by],
                ['role' => 'owner'],
            );
        });
    }
}
