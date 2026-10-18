<?php

namespace Database\Factories;

use App\Models\Community;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Community>
 */
class CommunityFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true).' '.Str::random(5);

        return [
            'created_by' => User::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => fake()->sentence(),
            'visibility' => 'public',
            'members_count' => 0,
        ];
    }

    public function private(): static
    {
        return $this->state(['visibility' => 'private']);
    }
}
