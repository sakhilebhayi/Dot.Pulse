<?php

namespace App\Services\Pulse\Discovery;

use App\Models\PulsePlace;

/**
 * Everything the matcher knows about one person, built from what they declared: their professional profile, their
 * open needs, their business pages' offers and roles, who they follow, and what they muted or blocked.
 *
 * It deliberately holds no age, gender, race, religion, health, disability, marital status or postcode. See
 * Matcher::FEATURES for the complete list of inputs.
 */
final class UserContext
{
    public ?PulsePlace $place = null;

    public string $reach = 'region';

    /** @var list<string> */
    public array $skills = [];

    /** @var list<string> */
    public array $interests = [];

    /** @var list<string> */
    public array $openTo = [];

    public ?string $experienceBand = null;

    /** @var list<array{topics: list<string>, place: ?PulsePlace, max_rank: int, kind: string}> */
    public array $needs = [];

    /** @var list<string> topics of the person's own live offers */
    public array $offerTopics = [];

    /** @var list<array{skills: list<string>, band: ?string, remote: bool, place: ?PulsePlace, entity_id: int}> */
    public array $seeks = [];

    public bool $isEmployer = false;

    /** @var array<int, true> */
    public array $ownEntityIds = [];

    /** @var array<int, true> */
    public array $followedEntityIds = [];

    /** @var array<int, true> */
    public array $followedUserIds = [];

    /** @var array<int, true> */
    public array $communityIds = [];

    /** @var array<int, true> users blocked in either direction */
    public array $blockedUserIds = [];

    /** @var array<string, true> */
    public array $mutedTopics = [];

    /** @var array<string, true> entity ids and slugs */
    public array $mutedEntities = [];

    /** @var array<string, true> */
    public array $mutedUsers = [];

    /** @var array<string, true> */
    public array $mutedPlaces = [];

    /** @var array<string, true> inputs switched off, e.g. "remote" */
    public array $mutedInputs = [];

    /** @var list<string> names of the person's own employers' pages, lowercased (for hiding talent from them) */
    public array $employerNames = [];

    public function __construct(public readonly int $userId) {}

    /** The union of everything the person works in, follows or needs: the "topics I care about" set. */
    public function interestTopics(): array
    {
        $needTopics = [];
        foreach ($this->needs as $n) {
            $needTopics = array_merge($needTopics, $n['topics']);
        }

        return array_values(array_unique(array_merge($this->interests, $this->skills, $this->offerTopics, $needTopics)));
    }

    /** Furthest rank (0 city, 1 region, 2 country, 3 anywhere) the person looks, from their declared reach. */
    public function maxRank(): int
    {
        return match ($this->reach) {
            'city', 'local' => 0,
            'region' => 1,
            'country', 'national' => 2,
            default => 3,
        };
    }
}
