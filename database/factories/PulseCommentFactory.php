<?php

namespace Database\Factories;

use App\Models\PulseComment;
use App\Models\PulsePost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PulseComment>
 */
class PulseCommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pulse_post_id' => PulsePost::factory(),
            'user_id' => User::factory(),
            'parent_id' => null,
            'body' => fake()->sentence(8),
        ];
    }
}
