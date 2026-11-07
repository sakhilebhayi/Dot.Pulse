<?php

namespace Tests\Feature\Discovery;

use App\Models\PulsePlace;
use App\Services\Pulse\Discovery\Places\PlaceRepository;
use Database\Seeders\PulsePlaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DiscoveryFixtures;
use Tests\TestCase;

class PlacesTest extends TestCase
{
    use DiscoveryFixtures;
    use RefreshDatabase;

    private function repo(): PlaceRepository
    {
        return app(PlaceRepository::class);
    }

    public function test_the_seeder_loads_the_gazetteer_and_is_idempotent(): void
    {
        $this->artisan('pulse:places:seed')->assertSuccessful();
        $count = PulsePlace::count();

        $this->artisan('pulse:places:seed')->assertSuccessful();

        $this->assertSame($count, PulsePlace::count(), 'running twice changes nothing');
        $this->assertGreaterThanOrEqual(190, PulsePlace::where('level', 'country')->count(), 'every country');
        $this->assertSame(9, PulsePlace::where('level', 'region')->where('country_code', 'ZA')->count(), 'nine provinces');
        $this->assertGreaterThanOrEqual(300, PulsePlace::where('level', 'city')->where('country_code', 'ZA')->count(), 'a few hundred South African cities, towns and suburbs');
        $this->assertGreaterThanOrEqual(100, PulsePlace::where('level', 'city')->where('country_code', '!=', 'ZA')->count(), 'major world cities');
        $this->assertSame(PulsePlace::count(), PulsePlace::distinct('slug')->count('slug'), 'slugs are unique');
    }

    public function test_the_seeder_updates_in_place_without_duplicating(): void
    {
        $this->artisan('pulse:places:seed')->assertSuccessful();
        $id = PulsePlace::where('slug', 'sandton-johannesburg')->value('id');
        PulsePlace::where('id', $id)->update(['lat' => 1.0, 'name' => 'Wrong']);

        $this->artisan('pulse:places:seed')->assertSuccessful();

        $fixed = PulsePlace::find($id);
        $this->assertSame('Sandton, Johannesburg', $fixed->name);
        $this->assertEqualsWithDelta(-26.11, $fixed->lat, 0.01);
    }

    public function test_coordinates_are_rough_public_centroids_inside_valid_ranges(): void
    {
        $this->artisan('pulse:places:seed')->assertSuccessful();

        $this->assertSame(0, PulsePlace::where('lat', '<', -90)->orWhere('lat', '>', 90)->orWhere('lng', '<', -180)->orWhere('lng', '>', 180)->count());
        $this->assertSame(0, PulsePlace::whereNull('lat')->orWhereNull('lng')->count());

        // Every South African town sits roughly inside South Africa.
        $outside = PulsePlace::where('country_code', 'ZA')->where('level', '!=', 'country')
            ->where(fn ($q) => $q->where('lat', '>', -22)->orWhere('lat', '<', -35)->orWhere('lng', '<', 16)->orWhere('lng', '>', 33))->count();
        $this->assertSame(0, $outside);
    }

    public function test_the_gazetteer_stores_places_only_never_a_persons_position(): void
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('pulse_places');
        $this->assertNotContains('user_id', $columns);
        $this->assertSame([], array_intersect(['user_id', 'ip', 'ip_address', 'accuracy', 'device'], $columns));

        $profileColumns = DB::getSchemaBuilder()->getColumnListing('pulse_profiles');
        $this->assertSame([], array_intersect(['lat', 'lng', 'latitude', 'longitude', 'geo', 'ip_address'], $profileColumns));
    }

    public function test_search_finds_by_name_with_exact_and_south_african_matches_first(): void
    {
        $this->artisan('pulse:places:seed')->assertSuccessful();

        $names = $this->repo()->search('sandton')->pluck('name')->all();
        $this->assertSame('Sandton, Johannesburg', $names[0]);

        $this->assertSame('Johannesburg', $this->repo()->search('johannesburg')->first()->name);
        $this->assertSame('South Africa', $this->repo()->search('south africa')->first()->name);
        $this->assertTrue($this->repo()->search('')->isEmpty());
        $this->assertTrue($this->repo()->search('x')->isEmpty());
        $this->assertTrue($this->repo()->search('%')->isEmpty(), 'wildcards are literal');
        $this->assertLessThanOrEqual(10, $this->repo()->search('a')->count());
    }

    public function test_labels_read_like_an_address_a_person_would_write(): void
    {
        $this->artisan('pulse:places:seed')->assertSuccessful();
        $label = fn (string $slug) => $this->repo()->label(PulsePlace::where('slug', $slug)->first());

        $this->assertSame('Sandton, Johannesburg', $label('sandton-johannesburg'));
        $this->assertSame('Pretoria, Gauteng', $label('pretoria-za'));
        $this->assertSame('Gauteng, South Africa', $label('gauteng-za'));
        $this->assertSame('South Africa', $label('south-africa'));
        $this->assertSame('London, England', $label('london-gb'));
    }

    public function test_nearby_ids_come_in_tiers_city_then_region_then_country(): void
    {
        $g = $this->dGeo();

        $tiers = $this->repo()->nearbyIds($g->sandton, 'national');

        $this->assertEqualsCanonicalizing([$g->jhb->id, $g->sandton->id], $tiers['city'], 'a suburb and its metro are one city');
        $this->assertEqualsCanonicalizing([$g->gauteng->id, $g->pta->id], $tiers['region']);
        $this->assertEqualsCanonicalizing([$g->za->id, $g->wc->id, $g->cpt->id], $tiers['country']);
        $this->assertNotContains($g->lagos->id, array_merge(...array_values($tiers)));

        $this->assertSame([], $this->repo()->nearbyIds($g->sandton, 'local')['region']);
        $this->assertSame([], $this->repo()->nearbyIds($g->sandton, 'local')['country']);
        $this->assertSame([], $this->repo()->nearbyIds($g->sandton, 'region')['country']);
        $this->assertNotEmpty($this->repo()->nearbyIds($g->sandton, 'region')['region']);

        $flat = $this->repo()->flatNearbyIds($g->sandton, 'national');
        $this->assertSame(array_unique($flat), $flat);
        $this->assertSame(array_merge($tiers['city'], $tiers['region'], $tiers['country']), $flat, 'nearest first');
    }

    public function test_nearby_for_a_region_or_country_place_starts_at_its_own_level(): void
    {
        $g = $this->dGeo();

        $region = $this->repo()->nearbyIds($g->gauteng, 'national');
        $this->assertSame([], $region['city']);
        $this->assertContains($g->gauteng->id, $region['region']);
        $this->assertContains($g->sandton->id, $region['region']);

        $country = $this->repo()->nearbyIds($g->za, 'national');
        $this->assertSame([], $country['city']);
        $this->assertSame([], $country['region']);
        $this->assertContains($g->za->id, $country['country']);
    }

    public function test_rank_is_the_declared_hierarchy_not_a_distance(): void
    {
        $g = $this->dGeo();
        $r = $this->repo();

        $this->assertSame(0, $r->rank($g->jhb, $g->jhb));
        $this->assertSame(0, $r->rank($g->jhb, $g->sandton));
        $this->assertSame(1, $r->rank($g->jhb, $g->pta));
        $this->assertSame(2, $r->rank($g->jhb, $g->cpt));
        $this->assertSame(3, $r->rank($g->jhb, $g->lagos));
        $this->assertSame(1, $r->rank($g->jhb, $g->gauteng), 'a region level place is no closer than the region');
        $this->assertSame(2, $r->rank($g->jhb, $g->za));
        $this->assertNull($r->rank($g->jhb, null));
        $this->assertNull($r->rank(null, null));
    }

    public function test_for_user_reads_the_declared_place_only(): void
    {
        $g = $this->dGeo();
        $with = $this->dUser(['place' => $g->pta]);
        $without = $this->dUser();

        $this->assertSame($g->pta->id, $this->repo()->forUser($with)->id);
        $this->assertNull($this->repo()->forUser($without));
    }
}
