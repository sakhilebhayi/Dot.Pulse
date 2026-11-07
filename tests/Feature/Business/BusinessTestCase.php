<?php

namespace Tests\Feature\Business;

use App\Models\PulseEntity;
use App\Models\PulseEntityMember;
use App\Models\PulsePlace;
use App\Models\PulseProfile;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fixtures for the business side screens. Moderation is pinned to "no rules installed" so every test means the same
 * thing whether or not the rules class is present: an author without an InfoDot company is pending, one with a
 * company is approved.
 */
abstract class BusinessTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pulse.entities.rules_class' => 'Tests\\Absent\\Rules']);
        Storage::fake(config('pulse.entities.media.disk', 'local'));
    }

    protected function person(array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        PulseProfile::query()->firstOrCreate(['user_id' => $user->id], ['role' => 'customer']);
        DB::table('pulse_profiles')->where('user_id', $user->id)->update(['age_confirmed_at' => now()->subDays(30), 'onboarded_at' => now()->subDays(30)]);

        return $user->fresh();
    }

    protected function moderator(): User
    {
        $user = $this->person();
        DB::table('pulse_profiles')->where('user_id', $user->id)->update(['role' => 'moderator']);

        return $user->fresh();
    }

    /** A person InfoDot has put in a company, so their listings publish without a human. */
    protected function infodotPerson(string $company = 'Acme Holdings'): User
    {
        $user = $this->person();
        $this->company($user, $company);

        return $user->fresh();
    }

    protected function company(User $user, string $name = 'Acme Holdings'): Team
    {
        return Team::forceCreate([
            'user_id' => $user->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid(),
        ]);
    }

    protected function page(?User $owner = null, array $attrs = []): PulseEntity
    {
        $owner ??= $this->infodotPerson();

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

    protected function place(string $name = 'Johannesburg', string $level = 'city', string $region = 'Gauteng'): PulsePlace
    {
        return PulsePlace::create([
            'level' => $level, 'country_code' => 'ZA', 'region' => $region, 'city' => $level === 'city' ? $name : null,
            'name' => $name, 'slug' => Str::slug("za $region $name $level"),
        ]);
    }

    /** @return array<string, mixed> */
    protected function offerData(array $over = []): array
    {
        return array_merge([
            'title' => 'Monthly bookkeeping for small shops',
            'summary' => 'Books kept, VAT filed, one fixed fee.',
            'description' => 'We keep your books accurate and your returns on time.',
            'price_mode' => 'from',
            'amount_cents' => 250000,
            'unit' => 'month',
        ], $over);
    }

    /** @return array<string, mixed> */
    protected function roleData(array $over = []): array
    {
        return array_merge([
            'title' => 'Junior bookkeeper',
            'summary' => 'Help our clients keep clean books.',
            'description' => 'You will reconcile bank accounts and prepare VAT returns.',
            'skills' => ['bookkeeping', 'excel'],
            'work_mode' => 'hybrid',
            'pay_text' => 'R15 000 to R20 000 a month',
        ], $over);
    }
}
