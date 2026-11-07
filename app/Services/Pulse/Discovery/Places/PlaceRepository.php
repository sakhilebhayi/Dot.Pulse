<?php

namespace App\Services\Pulse\Discovery\Places;

use App\Models\PulsePlace;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads the gazetteer. The product stores a declared place, never a coordinate of a person, and "nearby" is the
 * declared hierarchy (same city, then region, then country), not a distance.
 *
 * Tier ranks used everywhere in matching: 0 city, 1 region, 2 country, 3 elsewhere.
 */
class PlaceRepository
{
    public const RANK_CITY = 0;

    public const RANK_REGION = 1;

    public const RANK_COUNTRY = 2;

    public const RANK_WORLD = 3;

    /** @var array<int, PulsePlace|null> */
    private array $found = [];

    /** @var array<string, array<string, list<int>>> */
    private array $tiers = [];

    /** @var array<string, string> */
    private array $countryNames = [];

    /** Search by typed name: exact first, then prefix, then contains; cities before regions before countries. */
    public function search(string $q, int $limit = 10): Collection
    {
        $needle = Str::lower(trim($q));

        if (mb_strlen($needle) < 2) {
            return collect();
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle).'%';

        $levelOrder = ['city' => 1, 'region' => 2, 'country' => 3];

        return PulsePlace::query()
            ->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])
            ->limit(60)
            ->get()
            ->sortBy(function (PulsePlace $p) use ($needle, $levelOrder) {
                $name = Str::lower($p->name);
                $match = $name === $needle ? 0 : (str_starts_with($name, $needle) ? 1 : 2);

                return sprintf('%d-%d-%d-%s', $match, $p->country_code === 'ZA' ? 0 : 1, $levelOrder[$p->level] ?? 4, $name);
            })
            ->take($limit)
            ->values();
    }

    public function find(int $id): ?PulsePlace
    {
        return $this->found[$id] ??= PulsePlace::find($id);
    }

    public function findBySlug(string $slug): ?PulsePlace
    {
        return PulsePlace::where('slug', $slug)->first();
    }

    /** The place a person declared on their profile, if any. */
    public function forUser(User $user): ?PulsePlace
    {
        $placeId = DB::table('pulse_profiles')->where('user_id', $user->id)->value('place_id');

        return $placeId ? $this->find((int) $placeId) : null;
    }

    /** "Sandton, Johannesburg" / "Johannesburg, Gauteng" / "Gauteng, South Africa" / "South Africa". */
    public function label(PulsePlace $place): string
    {
        return match ($place->level) {
            'country' => $place->name,
            'region' => $place->name.', '.$this->countryName($place->country_code),
            default => $place->region && $place->region !== $place->name && ! str_contains($place->name, ',')
                ? $place->name.', '.$place->region
                : $place->name,
        };
    }

    public function countryName(string $code): string
    {
        if ($this->countryNames === []) {
            $this->countryNames = DB::table('pulse_places')->where('level', 'country')->pluck('name', 'country_code')->all();
        }

        return $this->countryNames[$code] ?? $code;
    }

    /**
     * Ids around a place in tiers, nearest first. Tiers beyond the reach are empty arrays.
     *
     * reach: local|city (same city only), region, national|country (city, region and country), global|remote (same as
     * country: a global reach means the caller does not filter by place at all).
     *
     * @return array{city: list<int>, region: list<int>, country: list<int>}
     */
    public function nearbyIds(PulsePlace $place, string $reach = 'country'): array
    {
        $depth = match ($reach) {
            'local', 'city' => 0,
            'region' => 1,
            default => 2,
        };

        $all = $this->tiers[$place->id] ??= $this->computeTiers($place);

        return [
            'city' => $all['city'],
            'region' => $depth >= 1 ? $all['region'] : [],
            'country' => $depth >= 2 ? $all['country'] : [],
        ];
    }

    /** @return list<int> every id within the reach, nearest tier first */
    public function flatNearbyIds(PulsePlace $place, string $reach = 'country'): array
    {
        return array_merge(...array_values($this->nearbyIds($place, $reach)));
    }

    /**
     * How far apart two declared places are: 0 same city, 1 same region, 2 same country, 3 elsewhere.
     * Null when either place is unknown. A region level place can be no closer than 1, a country level place no
     * closer than 2: a coarse place cannot be "in your city".
     */
    public function rank(?PulsePlace $a, ?PulsePlace $b): ?int
    {
        if ($a === null || $b === null) {
            return null;
        }

        if ($a->country_code !== $b->country_code) {
            return self::RANK_WORLD;
        }

        $floor = max($this->levelFloor($a), $this->levelFloor($b));

        if ($a->id === $b->id) {
            return max(self::RANK_CITY, $floor);
        }

        if ($a->region !== null && $a->region === $b->region) {
            $sameCity = $a->city !== null && $a->city === $b->city;

            return max($sameCity ? self::RANK_CITY : self::RANK_REGION, $floor);
        }

        return max(self::RANK_COUNTRY, $floor);
    }

    private function levelFloor(PulsePlace $p): int
    {
        return match ($p->level) {
            'country' => self::RANK_COUNTRY,
            'region' => self::RANK_REGION,
            default => self::RANK_CITY,
        };
    }

    /** @return array{city: list<int>, region: list<int>, country: list<int>} */
    private function computeTiers(PulsePlace $p): array
    {
        $city = [];
        $region = [];

        if ($p->level === 'city') {
            $city = ($p->region !== null && $p->city !== null)
                ? PulsePlace::where('country_code', $p->country_code)->where('region', $p->region)->where('city', $p->city)->pluck('id')->all()
                : [$p->id];
        }

        if ($p->level !== 'country' && $p->region !== null) {
            $region = PulsePlace::where('country_code', $p->country_code)->where('region', $p->region)
                ->whereNotIn('id', $city ?: [0])->pluck('id')->all();
        } elseif ($p->level === 'region') {
            $region = [$p->id];
        }

        $used = array_merge($city, $region);

        if ($p->level === 'city' && $city === []) {
            $city = [$p->id];
            $used[] = $p->id;
        }

        $country = PulsePlace::where('country_code', $p->country_code)
            ->whereNotIn('id', $used ?: [0])->pluck('id')->all();

        return [
            'city' => array_map('intval', $city),
            'region' => array_map('intval', $region),
            'country' => array_map('intval', $country),
        ];
    }
}
