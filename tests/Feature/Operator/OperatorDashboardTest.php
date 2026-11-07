<?php

namespace Tests\Feature\Operator;

use App\Livewire\Pulse\Operator\Dashboard;
use App\Models\PulseConversation;
use App\Models\PulseProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

class OperatorDashboardTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/operator')->assertRedirect('/login');
    }

    public function test_an_ordinary_member_gets_a_403(): void
    {
        $this->actingAs($this->pulseUser())->get('/operator')->assertForbidden();
        $this->actingAs($this->pulseUser('customer'))->get('/operator')->assertForbidden();
    }

    public function test_a_community_moderator_is_not_an_operator(): void
    {
        $community = $this->publicCommunity();
        $manager = $this->joined($community, null, 'moderator');

        $this->actingAs($manager)->get('/operator')->assertForbidden();
    }

    public function test_a_platform_moderator_sees_the_page(): void
    {
        $this->actingAs($this->platformModerator())->get('/operator')
            ->assertOk()
            ->assertSee('Operator')
            ->assertSee('Real counts from real rows');
    }

    public function test_the_page_loads_twice_on_a_store_that_does_not_rebuild_objects(): void
    {
        // Production caches to a real store with cache.serializable_classes = false: a Carbon in the cached
        // snapshot came back as an incomplete object and the second load was a 500. The array store hides it.
        $dir = sys_get_temp_dir().'/pulse-cache-'.uniqid();
        config(['cache.default' => 'file', 'cache.stores.file.path' => $dir, 'cache.serializable_classes' => false]);
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        \Illuminate\Support\Facades\Cache::clearResolvedInstances();

        $mod = $this->platformModerator();
        $this->actingAs($mod)->get('/operator')->assertOk();
        $this->actingAs($mod)->get('/operator')->assertOk()->assertSee('Range');
        $this->actingAs($mod)->get('/operator')->assertOk();

        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($dir);
    }

    public function test_the_component_refuses_anyone_else(): void
    {
        Livewire::actingAs($this->pulseUser())->test(Dashboard::class)->assertForbidden();
    }

    public function test_an_empty_platform_says_no_data_yet_and_never_a_bare_zero(): void
    {
        Livewire::actingAs($this->platformModerator())->test(Dashboard::class)
            ->assertSee('No data yet')
            ->assertSee('Nothing waiting')
            ->assertDontSee('Response rate 0')
            ->assertSee('Range');
    }

    public function test_it_shows_real_numbers_with_the_range_stated_and_switches_range(): void
    {
        $c = PulseConversation::forceCreate([
            'type' => 'direct', 'kind' => 'enquiry', 'status' => 'open', 'card' => ['what' => 'x'],
            'initiator_id' => User::factory()->create()->id, 'recipient_id' => User::factory()->create()->id,
            'first_response_at' => now()->subDay(), 'outcome' => 'won', 'outcome_side' => 'initiator',
            'outcome_initiator_at' => now()->subDays(2), 'outcome_recipient_at' => now()->subDay(),
        ]);
        DB::table('pulse_conversations')->where('id', $c->id)->update(['created_at' => now()->subDays(4)]);
        PulseProfile::factory()->discoverable('open')->create(['kind' => 'professional']);

        $component = Livewire::actingAs($this->platformModerator())->test(Dashboard::class)
            ->assertSee('30 days')
            ->assertSee('Knocks sent')
            ->assertSee('Confirmed results')
            ->assertSee('Won')
            ->assertSee('Show the numbers as a table');

        $component->call('setRange', 7)->assertSet('days', 7)->assertSee('7 days');
        $component->call('setRange', 5)->assertSet('days', 7); // only the offered ranges
    }

    public function test_the_page_uses_no_dashes_or_exclamations_in_its_copy(): void
    {
        $html = Livewire::actingAs($this->platformModerator())->test(Dashboard::class)->html();
        $text = html_entity_decode(strip_tags(preg_replace('/<svg.*?<\/svg>/s', '', $html)));

        $this->assertStringNotContainsString('—', $text);
        $this->assertStringNotContainsString('–', $text);
        $this->assertStringNotContainsString('!', $text);
    }

    public function test_duration_formatting(): void
    {
        $this->assertSame('45 s', Dashboard::duration(45));
        $this->assertSame('5 min', Dashboard::duration(300));
        $this->assertSame('3 h 20 min', Dashboard::duration(12000));
        $this->assertSame('2 d 4 h', Dashboard::duration(2 * 86400 + 4 * 3600));
        $this->assertNull(Dashboard::duration(null));
        $this->assertSame('75%', Dashboard::percent(0.75));
    }
}
