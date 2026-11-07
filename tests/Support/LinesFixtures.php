<?php

namespace Tests\Support;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseEntityMember;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulsePlace;
use App\Models\PulseProfile;
use App\Models\PulseRole;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Lines\LineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fixtures for the Lines tests. Self contained on purpose: no other agent's factories are used. Everything is built
 * with plain model creates and DB writes so the tests do not depend on how another service seeds its data.
 */
trait LinesFixtures
{
    /** An established adult with a profile. */
    protected function person(array $profile = [], array $user = []): User
    {
        $u = User::factory()->create(array_merge(['created_at' => now()->subDays(60)], $user));

        PulseProfile::query()->create(['user_id' => $u->id, 'role' => 'customer']);
        DB::table('pulse_profiles')->where('user_id', $u->id)->update(array_merge([
            'age_confirmed_at' => now()->subDays(30),
            'onboarded_at' => now()->subDays(30),
            'contact_policy' => 'context',
            'discoverability' => 'off',
        ], $profile));

        return $u->fresh();
    }

    /** A person who has not declared they are 18. */
    protected function minor(): User
    {
        $u = $this->person();
        DB::table('pulse_profiles')->where('user_id', $u->id)->update(['age_confirmed_at' => null]);

        return $u->fresh();
    }

    protected function policy(User $u, string $policy): User
    {
        DB::table('pulse_profiles')->where('user_id', $u->id)->update(['contact_policy' => $policy]);

        return $u->fresh();
    }

    protected function page(?User $owner = null, array $attrs = [], string $role = 'owner'): PulseEntity
    {
        $owner ??= $this->person();
        $name = $attrs['name'] ?? 'Moyo Plumbing '.Str::random(4);

        $entity = PulseEntity::query()->create(array_merge([
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => $name,
            'status' => 'live',
            'is_seller' => true,
            'contact_policy' => 'anyone',
            'created_by' => $owner->id,
        ], $attrs));

        PulseEntityMember::query()->create(['entity_id' => $entity->id, 'user_id' => $owner->id, 'role' => $role]);

        return $entity;
    }

    protected function member(PulseEntity $entity, ?User $user = null, string $role = 'responder'): User
    {
        $user ??= $this->person();
        PulseEntityMember::query()->create(['entity_id' => $entity->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    protected function offer(PulseEntity $entity, array $attrs = []): PulseOffer
    {
        $creator = PulseEntityMember::query()->where('entity_id', $entity->id)->value('user_id');

        return PulseOffer::query()->create(array_merge([
            'entity_id' => $entity->id,
            'created_by' => $creator,
            'slug' => 'offer-'.Str::lower(Str::random(6)),
            'title' => 'Geyser repair',
            'summary' => 'Same week geyser repairs',
            'price_mode' => 'quote',
            'status' => 'live',
            'moderation' => 'approved',
            'accepts_quotes' => true,
            'published_at' => now(),
        ], $attrs));
    }

    protected function role(PulseEntity $entity, array $attrs = []): PulseRole
    {
        $creator = PulseEntityMember::query()->where('entity_id', $entity->id)->value('user_id');

        return PulseRole::query()->create(array_merge([
            'entity_id' => $entity->id,
            'created_by' => $creator,
            'slug' => 'role-'.Str::lower(Str::random(6)),
            'title' => 'Site bookkeeper',
            'status' => 'live',
            'moderation' => 'approved',
            'published_at' => now(),
        ], $attrs));
    }

    protected function need(User $user, array $attrs = []): PulseNeed
    {
        return PulseNeed::query()->create(array_merge([
            'user_id' => $user->id,
            'title' => 'Need a plumber on Thursday',
            'details' => 'Burst pipe in the kitchen',
            'status' => 'open',
            'moderation' => 'approved',
            'published_at' => now(),
            'expires_at' => now()->addDays(10),
        ], $attrs));
    }

    /** A professional who can be found. Returns [user, signal]. */
    protected function talent(string $discoverability = 'anonymous', array $userAttrs = []): array
    {
        $pro = $this->person(['discoverability' => $discoverability, 'contact_policy' => 'context', 'experience_years' => 7], $userAttrs + ['name' => 'Thandiwe Zulu-Private', 'email' => 'thandi.private'.Str::lower(Str::random(4)).'@example.test']);

        $place = PulsePlace::query()->create(['level' => 'region', 'country_code' => 'ZA', 'region' => 'Gauteng', 'name' => 'Gauteng', 'slug' => 'gauteng-'.Str::lower(Str::random(4))]);

        $signal = PulseSignal::query()->create([
            'kind' => 'talent',
            'subject_type' => PulseProfile::class,
            'subject_id' => PulseProfile::query()->where('user_id', $pro->id)->value('id'),
            'owner_id' => $pro->id,
            'title' => 'Bookkeeper, 7 years, payroll and VAT',
            'excerpt' => 'Open to a new role',
            'skills' => ['bookkeeping', 'payroll', 'vat'],
            'topics' => ['finance'],
            'place_id' => $place->id,
            'anonymous' => $discoverability === 'anonymous',
            'status' => 'live',
            'published_at' => now(),
        ]);

        return [$pro, $signal];
    }

    protected function card(array $over = []): array
    {
        return array_merge([
            'what' => 'Fix a leaking geyser',
            'when_or_qty' => 'This Thursday',
            'where' => 'Soweto',
            'budget_band' => 'R1000 to R3000',
        ], $over);
    }

    /** An open line with a reply, ready for outcomes. */
    protected function openLine(User $from, PulseEntity|User $to, string $kind = 'enquiry', ?object $context = null): PulseConversation
    {
        $lines = app(LineService::class);
        $target = $to instanceof PulseEntity ? ['entity' => $to->id] : ['user' => $to->id];
        $c = $lines->knock($from, $target, $kind, $this->card(), $context);

        if ($c->status === PulseConversation::REQUESTED) {
            $responder = $to instanceof PulseEntity
                ? User::query()->find(PulseEntityMember::query()->where('entity_id', $to->id)->value('user_id'))
                : $to;
            $lines->accept($responder, $c);
        }

        return $c->refresh();
    }

    protected function responderOf(PulseEntity $entity): User
    {
        return User::query()->findOrFail(PulseEntityMember::query()->where('entity_id', $entity->id)->value('user_id'));
    }
}
