<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Dot.Pulse gazetteer: every country, the nine South African provinces, a few hundred South African
 * cities, towns and well known suburbs, and major world cities. Idempotent (upserts on the unique slug), so
 * `pulse:places:seed` can run on every deploy. Coordinates are rough public centroids of the place, never a
 * person's position; nothing computes a distance from them.
 */
class PulsePlaceSeeder extends Seeder
{
    /** @var array<string, array<string, mixed>> slug => row */
    private array $rows = [];

    /** @var array<string, string> ISO code => country name */
    private array $countryNames = [];

    public function run(): void
    {
        $this->rows = [];
        $dir = __DIR__.'/data';

        foreach (require $dir.'/pulse_places_countries.php' as $line) {
            [$cc, $name, $lat, $lng] = explode('|', $line);
            $this->countryNames[$cc] = $name;
            $this->add('country', $cc, null, null, $name, Str::slug($name), (float) $lat, (float) $lng);
        }

        $za = require $dir.'/pulse_places_za.php';

        foreach ($za['provinces'] as $province => [$lat, $lng]) {
            $this->add('region', 'ZA', $province, null, $province, Str::slug($province).'-za', $lat, $lng);
        }

        foreach ($za['towns'] as $province => $towns) {
            foreach ($towns as $line) {
                [$name, $lat, $lng] = explode('|', $line);
                $this->add('city', 'ZA', $province, $name, $name, Str::slug($name).'-za', (float) $lat, (float) $lng);
            }
        }

        foreach ($za['suburbs'] as $key => $suburbs) {
            [$metro, $province] = explode('|', $key);

            foreach ($suburbs as $line) {
                [$name, $lat, $lng] = explode('|', $line);
                $this->add('city', 'ZA', $province, $metro, $name.', '.$metro, Str::slug($name).'-'.Str::slug($metro), (float) $lat, (float) $lng);
            }
        }

        foreach (require $dir.'/pulse_places_world.php' as $line) {
            [$cc, $city, $region, $lat, $lng] = explode('|', $line);
            $this->add('city', $cc, $region !== '' ? $region : null, $city, $city, Str::slug($city).'-'.Str::lower($cc), (float) $lat, (float) $lng);
        }

        foreach (array_chunk(array_values($this->rows), 200) as $chunk) {
            DB::table('pulse_places')->upsert($chunk, ['slug'], ['level', 'country_code', 'region', 'city', 'name', 'lat', 'lng']);
        }
    }

    /** First one wins: a repeated slug is skipped, never overwritten, so slugs stay unique. */
    private function add(string $level, string $cc, ?string $region, ?string $city, string $name, string $slug, float $lat, float $lng): void
    {
        if ($slug === '' || isset($this->rows[$slug])) {
            return;
        }

        $this->rows[$slug] = [
            'level' => $level,
            'country_code' => $cc,
            'region' => $region !== null ? mb_substr($region, 0, 80) : null,
            'city' => $city !== null ? mb_substr($city, 0, 80) : null,
            'name' => mb_substr($name, 0, 120),
            'slug' => mb_substr($slug, 0, 140),
            'lat' => round($lat, 5),
            'lng' => round($lng, 5),
        ];
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
