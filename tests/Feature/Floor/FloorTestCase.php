<?php

namespace Tests\Feature\Floor;

use App\Models\PulsePlace;
use App\Models\PulseSignal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DiscoveryFixtures;
use Tests\TestCase;

/** Fixtures for the Floor screens: a signed-in person with a place, and signals with matches. */
abstract class FloorTestCase extends TestCase
{
    use DiscoveryFixtures;
    use RefreshDatabase;

    protected User $me;

    protected object $geo;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pulse.entities.rules_class' => 'Tests\\Absent\\Rules']);
        $this->geo = $this->dGeo();
        $this->me = $this->person(['place' => $this->geo->jhb]);
    }

    /** A signed-in-able person: personal team, a profile with a place, age confirmed. */
    protected function person(array $profile = [], array $user = []): User
    {
        $u = User::factory()->withPersonalTeam()->create(array_merge(['created_at' => now()->subDays(60)], $user));

        DB::table('pulse_profiles')->insert([
            'user_id' => $u->id,
            'skills' => json_encode($profile['skills'] ?? []),
            'expertise_tags' => json_encode([]),
            'interests' => json_encode($profile['interests'] ?? []),
            'open_to' => json_encode($profile['open_to'] ?? []),
            'place_id' => ($profile['place'] ?? null) instanceof PulsePlace ? $profile['place']->id : null,
            'reach' => 'region',
            'discoverability' => $profile['discoverability'] ?? 'off',
            'experience_years' => $profile['experience_years'] ?? null,
            'age_confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $u;
    }

    /** A news-kind signal from someone else, matched to $this->me. */
    protected function matched(array $attrs = [], float $score = 50.0, ?array $reasons = null, ?User $for = null): PulseSignal
    {
        $s = $this->dSignal(array_merge(['owner_id' => $this->person()->id, 'kind' => 'news', 'subject_type' => 'post'], $attrs));
        $this->dMatch($for ?? $this->me, $s, $score, $reasons);

        return $s;
    }

    /** An offer from a live business, with a real offer row, matched to $this->me. */
    protected function offerFor(string $title = 'Bookkeeping for small shops', ?array $reasons = null, ?User $for = null): array
    {
        $owner = $this->person();
        $entity = $this->dEntity($owner, ['name' => 'Sample Books '.(++$this->dSeq), 'is_seller' => true]);
        $signal = $this->dOfferSignal($entity, ['bookkeeping'], ['title' => $title, 'place' => $this->geo->jhb, 'excerpt' => 'Monthly books and VAT returns.']);
        $match = $this->dMatch($for ?? $this->me, $signal, 70, $reasons ?? [
            ['code' => 'need_offer', 'text' => 'You need bookkeeping, they offer bookkeeping', 'weight' => 40, 'ref' => 'bookkeeping'],
            ['code' => 'place_city', 'text' => 'Both in Johannesburg', 'weight' => 14, 'ref' => (string) $this->geo->jhb->slug],
            ['code' => 'fresh', 'text' => 'Posted today', 'weight' => 5, 'ref' => null],
        ]);

        return [$signal, $entity, $match, $owner];
    }
}
