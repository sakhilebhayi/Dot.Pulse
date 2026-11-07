<?php

namespace Database\Factories;

use App\Models\PulseEntity;
use App\Models\PulseEntityMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PulseEntityMember>
 */
class PulseEntityMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'entity_id' => PulseEntity::factory(),
            'user_id' => User::factory(),
            'role' => 'editor',
        ];
    }

    public function role(string $role): static
    {
        return $this->state(['role' => $role]);
    }
}
