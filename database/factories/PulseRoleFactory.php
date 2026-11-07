<?php

namespace Database\Factories;

use App\Models\PulseEntity;
use App\Models\PulseRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PulseRole>
 */
class PulseRoleFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->jobTitle();

        return [
            'entity_id' => PulseEntity::factory()->employer(),
            'created_by' => User::factory(),
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'title' => $title,
            'summary' => fake()->sentence(10),
            'description' => fake()->paragraph(),
            'employment_type' => 'full_time',
            'work_mode' => 'hybrid',
            'seniority' => 'mid',
            'skills' => ['bookkeeping', 'excel'],
            'pay_text' => 'R25 000 to R35 000 a month',
            'apply_mode' => 'on_pulse',
            'status' => 'live',
            'moderation' => 'approved',
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft', 'published_at' => null]);
    }

    public function pendingModeration(): static
    {
        return $this->state(['moderation' => 'pending']);
    }
}
