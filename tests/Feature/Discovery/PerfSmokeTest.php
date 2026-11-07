<?php

namespace Tests\Feature\Discovery;

use App\Services\Pulse\Discovery\Floor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DiscoveryFixtures;
use Tests\TestCase;

class PerfSmokeTest extends TestCase
{
    use DiscoveryFixtures;
    use RefreshDatabase;

    public function test_a_full_pool_of_two_hundred_matches_orders_quickly(): void
    {
        $me = $this->dUser();
        $kinds = ['news', 'ask', 'event', 'offer', 'need'];
        $owners = collect(range(1, 15))->map(fn () => $this->dUser());

        foreach (range(1, 200) as $i) {
            $s = $this->dSignal(['kind' => $kinds[$i % 5], 'subject_type' => 'post', 'owner_id' => $owners[$i % 15]->id]);
            $this->dMatch($me, $s, 100 - ($i % 70));
        }

        $start = microtime(true);
        $round = app(Floor::class)->round($me);
        $elapsed = microtime(true) - $start;

        $this->assertCount(20, $round['beats']);
        $this->assertSame(200, $round['counts']['new']);
        $this->assertLessThan(3.0, $elapsed, 'ordering 200 matches should take well under a second');
    }
}
