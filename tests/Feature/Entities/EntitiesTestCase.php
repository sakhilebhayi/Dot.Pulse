<?php

namespace Tests\Feature\Entities;

use App\Models\PulseEntity;
use App\Models\PulseEntityMember;
use App\Models\PulsePlace;
use App\Models\PulseProfile;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared fixtures. Moderation is pinned to a known rules class so these tests mean the same thing
 * whether or not Discovery\Moderation\LocalRules has been merged.
 */
abstract class EntitiesTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules('Tests\\Absent\\Rules'); // no rules installed
    }

    /** Pick the moderation rules class for this test. */
    protected function rules(string $class): void
    {
        config(['pulse.entities.rules_class' => $class]);
    }

    protected function person(array $attrs = []): User
    {
        return User::factory()->create($attrs);
    }

    protected function moderator(): User
    {
        $user = $this->person();
        PulseProfile::create(['user_id' => $user->id, 'role' => 'moderator']);

        return $user;
    }

    /** A person InfoDot has put in a company: a non-personal team with an InfoDot uuid. */
    protected function infodotPerson(): User
    {
        $user = $this->person();
        $this->companyFor($user);

        return $user->refresh();
    }

    protected function companyFor(User $user, string $name = 'Acme Holdings'): Team
    {
        return Team::forceCreate([
            'user_id' => $user->id,
            'name' => $name,
            'personal_team' => false,
            'infodot_team_uuid' => (string) Str::uuid(),
        ]);
    }

    /** A live, approved page with $owner as its owner. */
    protected function page(?User $owner = null, array $attrs = []): PulseEntity
    {
        $owner ??= $this->person();

        $entity = PulseEntity::factory()->create(array_merge(['created_by' => $owner->id, 'is_seller' => true, 'is_employer' => true], $attrs));
        PulseEntityMember::create(['entity_id' => $entity->id, 'user_id' => $owner->id, 'role' => 'owner']);

        return $entity;
    }

    protected function member(PulseEntity $entity, string $role): User
    {
        $user = $this->person();
        PulseEntityMember::create(['entity_id' => $entity->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    protected function place(string $level = 'city', string $name = 'Johannesburg', string $region = 'Gauteng', string $cc = 'ZA'): PulsePlace
    {
        return PulsePlace::create([
            'level' => $level, 'country_code' => $cc, 'region' => $region, 'city' => $level === 'city' ? $name : null,
            'name' => $name, 'slug' => Str::slug("$cc $region $name $level"),
        ]);
    }

    /** Valid offer input. */
    protected function offerData(array $over = []): array
    {
        return array_merge([
            'title' => 'Monthly bookkeeping for small businesses',
            'summary' => 'Books kept, VAT filed, one fixed monthly fee.',
            'description' => str_repeat('We keep your books accurate and your SARS returns on time. ', 3),
            'category' => 'Bookkeeping',
            'tags' => ['accounting', 'vat', 'payroll'],
            'price_mode' => 'from',
            'amount_cents' => 250000,
            'unit' => 'month',
        ], $over);
    }

    protected function roleData(array $over = []): array
    {
        return array_merge([
            'title' => 'Junior bookkeeper',
            'summary' => 'Help our clients keep clean books.',
            'description' => str_repeat('You will reconcile bank accounts and prepare VAT returns. ', 3),
            'skills' => ['bookkeeping', 'excel', 'vat'],
            'work_mode' => 'hybrid',
            'pay_text' => 'R15 000 to R20 000 a month',
        ], $over);
    }
}
