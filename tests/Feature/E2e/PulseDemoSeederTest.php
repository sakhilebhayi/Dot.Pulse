<?php

namespace Tests\Feature\E2e;

use App\Models\PulseConversation;
use App\Models\PulseDoor;
use App\Models\PulseEntity;
use App\Models\PulseMatch;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseQuote;
use App\Models\PulseReview;
use App\Models\PulseRole;
use App\Models\User;
use Database\Seeders\PulseDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PulseDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('pulse:demo-seed')->assertFailed();

        $this->assertSame(0, User::count());
        $this->assertSame(0, PulseEntity::count());

        $this->expectException(\RuntimeException::class);
        (new PulseDemoSeeder)->run();
    }

    public function test_it_builds_the_demo_world_through_the_real_services_and_is_idempotent(): void
    {
        $this->artisan('pulse:demo-seed')->assertSuccessful();

        $counts = fn () => [
            'users' => User::count(),
            'entities' => PulseEntity::count(),
            'offers' => PulseOffer::count(),
            'roles' => PulseRole::count(),
            'needs' => PulseNeed::count(),
            'doors' => PulseDoor::count(),
            'lines' => PulseConversation::count(),
            'reviews' => PulseReview::count(),
        ];

        $first = $counts();

        $this->assertGreaterThanOrEqual(12, $first['users']);
        $this->assertSame(8, $first['entities']);
        $this->assertSame(6, $first['needs']);
        $this->assertSame(2, $first['doors']);
        $this->assertGreaterThan(0, PulseMatch::count());

        // A request, an open line, three quote slips, a confirmed outcome with both reviews revealed.
        $this->assertSame(1, PulseConversation::where('status', 'requested')->count());
        $this->assertSame(3, PulseConversation::where('kind', 'quote')->count());
        $this->assertSame(3, PulseQuote::where('status', 'sent')->count());
        $this->assertSame(2, PulseReview::whereNotNull('revealed_at')->count());

        // The ladder is only what is real: no page is ever "verified by InfoDot".
        $this->assertSame(0, PulseEntity::where('verification_status', 'infodot_verified')->count());
        $this->assertSame(2, PulseEntity::where('verification_status', 'infodot_member')->count());

        $this->artisan('pulse:demo-seed')->assertSuccessful();

        $this->assertSame($first, $counts());
    }

    public function test_the_dev_login_route_signs_a_demo_person_in_under_testing(): void
    {
        $this->artisan('pulse:demo-seed')->assertSuccessful();
        $user = User::where('email', PulseDemoSeeder::email('naledi'))->firstOrFail();

        $this->get('/dev/login/'.$user->id)->assertRedirect(route('floor'));
        $this->assertAuthenticatedAs($user);

        $this->get('/dev/logout');
        $this->assertGuest();

        $this->get('/dev/login/'.urlencode($user->email).'?to=/lines')->assertRedirect('/lines');
        $this->get('/dev/logout');
        $this->get('/dev/login/'.$user->id.'?to=//evil.example')->assertRedirect(route('floor'));
    }
}
