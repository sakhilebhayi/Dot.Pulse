<?php

namespace Tests\Support;

use App\Models\PulseEntity;
use App\Models\PulseMatch;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulsePlace;
use App\Models\PulseRole;
use App\Models\PulseSignal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Local fixture builders for the discovery tests. They create rows directly, on purpose: nothing here depends on
 * another module's factories, so these tests keep working however those modules change.
 */
trait DiscoveryFixtures
{
    private int $dSeq = 0;

    protected function dPlace(string $name, string $level = 'city', string $cc = 'ZA', ?string $region = null, ?string $city = null): PulsePlace
    {
        return PulsePlace::create([
            'level' => $level,
            'country_code' => $cc,
            'region' => $region,
            'city' => $level === 'city' ? ($city ?? $name) : null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower($cc).'-'.(++$this->dSeq),
            'lat' => 0.0,
            'lng' => 0.0,
        ]);
    }

    /** A small South African hierarchy: country, two provinces, Johannesburg with a suburb, Pretoria, Cape Town. */
    protected function dGeo(): object
    {
        $g = new \stdClass;
        $g->za = $this->dPlace('South Africa', 'country');
        $g->gauteng = $this->dPlace('Gauteng', 'region', 'ZA', 'Gauteng');
        $g->wc = $this->dPlace('Western Cape', 'region', 'ZA', 'Western Cape');
        $g->jhb = $this->dPlace('Johannesburg', 'city', 'ZA', 'Gauteng', 'Johannesburg');
        $g->sandton = $this->dPlace('Sandton, Johannesburg', 'city', 'ZA', 'Gauteng', 'Johannesburg');
        $g->pta = $this->dPlace('Pretoria', 'city', 'ZA', 'Gauteng', 'Pretoria');
        $g->cpt = $this->dPlace('Cape Town', 'city', 'ZA', 'Western Cape', 'Cape Town');
        $g->lagos = $this->dPlace('Lagos', 'city', 'NG', 'Lagos', 'Lagos');

        return $g;
    }

    /**
     * @param  array<string, mixed>  $profile  place (PulsePlace|null), skills, interests, open_to, reach, experience_years,
     *                                         discoverability, age_confirmed (default true), expertise_tags, work_history
     * @param  array<string, mixed>  $user
     */
    protected function dUser(array $profile = [], array $user = []): User
    {
        $u = User::factory()->create(array_merge(['created_at' => now()->subDays(60)], $user));

        $place = $profile['place'] ?? null;

        $row = [
            'user_id' => $u->id,
            'skills' => json_encode($profile['skills'] ?? []),
            'expertise_tags' => json_encode($profile['expertise_tags'] ?? []),
            'interests' => json_encode($profile['interests'] ?? []),
            'open_to' => json_encode($profile['open_to'] ?? []),
            'place_id' => $place instanceof PulsePlace ? $place->id : $place,
            'reach' => $profile['reach'] ?? 'region',
            'experience_years' => $profile['experience_years'] ?? null,
            'discoverability' => $profile['discoverability'] ?? 'off',
            'work_history' => isset($profile['work_history']) ? json_encode($profile['work_history']) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('pulse_profiles', 'age_confirmed_at')) {
            $row['age_confirmed_at'] = ($profile['age_confirmed'] ?? true) ? now() : null;
        }

        DB::table('pulse_profiles')->insert($row);

        return $u;
    }

    /** @param array<string, mixed> $attrs */
    protected function dEntity(User $owner, array $attrs = []): PulseEntity
    {
        $name = $attrs['name'] ?? 'Business '.(++$this->dSeq);

        $e = PulseEntity::forceCreate(array_merge([
            'slug' => Str::slug($name).'-'.(++$this->dSeq),
            'name' => $name,
            'is_seller' => true,
            'is_employer' => false,
            'status' => 'live',
            'contact_policy' => 'anyone',
            'hiring_signal' => 'none',
            'verification_status' => 'unverified',
            'created_by' => $owner->id,
        ], $attrs));

        DB::table('pulse_entity_members')->insert(['entity_id' => $e->id, 'user_id' => $owner->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);

        return $e;
    }

    /** @param array<string, mixed> $attrs */
    protected function dOffer(PulseEntity $e, array $attrs = []): PulseOffer
    {
        $title = $attrs['title'] ?? 'Offer '.(++$this->dSeq);

        return PulseOffer::create(array_merge([
            'entity_id' => $e->id,
            'created_by' => $e->created_by,
            'slug' => Str::slug($title).'-'.(++$this->dSeq),
            'title' => $title,
            'status' => 'live',
            'moderation' => 'approved',
            'published_at' => now(),
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    protected function dNeed(User $u, array $attrs = []): PulseNeed
    {
        return PulseNeed::create(array_merge([
            'user_id' => $u->id,
            'title' => 'Need '.(++$this->dSeq),
            'status' => 'open',
            'moderation' => 'approved',
            'visibility' => 'open',
            'reach' => 'region',
            'published_at' => now(),
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    protected function dRole(PulseEntity $e, array $attrs = []): PulseRole
    {
        $title = $attrs['title'] ?? 'Role '.(++$this->dSeq);

        return PulseRole::create(array_merge([
            'entity_id' => $e->id,
            'created_by' => $e->created_by,
            'slug' => Str::slug($title).'-'.(++$this->dSeq),
            'title' => $title,
            'status' => 'live',
            'moderation' => 'approved',
            'published_at' => now(),
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    protected function dSignal(array $attrs = []): PulseSignal
    {
        $n = ++$this->dSeq;
        $place = $attrs['place'] ?? null;
        unset($attrs['place']);

        return PulseSignal::create(array_merge([
            'kind' => 'offer',
            'subject_type' => 'offer',
            'subject_id' => 900000 + $n,
            'title' => 'Signal '.$n,
            'topics' => [],
            'skills' => [],
            'place_id' => $place instanceof PulsePlace ? $place->id : $place,
            'reach' => 'national',
            'is_remote' => false,
            'anonymous' => false,
            'quality' => 0.5,
            'status' => 'live',
            'published_at' => now()->subHours(2),
        ], $attrs));
    }

    /** An offer signal from a business, with a real offer row behind it. */
    protected function dOfferSignal(PulseEntity $e, array $topics, array $attrs = []): PulseSignal
    {
        $offer = $this->dOffer($e, ['title' => $attrs['title'] ?? 'Offer '.implode(' ', $topics), 'tags' => $topics]);

        return $this->dSignal(array_merge([
            'kind' => 'offer',
            'subject_type' => 'offer',
            'subject_id' => $offer->id,
            'entity_id' => $e->id,
            'owner_id' => $e->created_by,
            'title' => $offer->title,
            'topics' => $topics,
        ], $attrs));
    }

    protected function dMatch(User $u, PulseSignal $s, float $score = 50.0, ?array $reasons = null): PulseMatch
    {
        return PulseMatch::create([
            'user_id' => $u->id,
            'signal_id' => $s->id,
            'score' => $score,
            'reasons' => $reasons ?? [['code' => 'topic', 'text' => 'A reason.', 'weight' => $score, 'ref' => 'bookkeeping']],
            'computed_at' => now(),
        ]);
    }

    protected function dFollowEntity(User $u, PulseEntity $e): void
    {
        DB::table('pulse_entity_followers')->insert(['entity_id' => $e->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function dBlock(User $blocker, User $blocked): void
    {
        DB::table('pulse_blocks')->insert(['blocker_id' => $blocker->id, 'blocked_id' => $blocked->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function dMute(User $u, string $kind, string $target): void
    {
        DB::table('pulse_mutes')->insert(['user_id' => $u->id, 'kind' => $kind, 'target' => $target, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $attrs */
    protected function dPrefs(User $u, array $attrs = []): void
    {
        DB::table('pulse_notification_prefs')->insert(array_merge([
            'user_id' => $u->id,
            'digest' => true,
            'frequency' => 'daily',
            'digest_hour' => '07:30',
            'timezone' => 'Africa/Johannesburg',
            'channels' => json_encode(['in_app' => true, 'email' => true]),
            'categories' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ], array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $attrs)));
    }
}
