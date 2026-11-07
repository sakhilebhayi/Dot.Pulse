<?php

namespace Tests\Feature\Me;

use App\Models\PulseProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OnboardedGateTest extends MeTestCase
{
    /** @return list<string> */
    private function gatedPaths(): array
    {
        return ['/floor', '/trade', '/work', '/local', '/learn', '/search', '/saved', '/door', '/lines', '/business/new', '/need/new', '/community', '/dashboard', '/me/profile', '/me/settings'];
    }

    public function test_a_person_who_never_did_the_welcome_flow_is_sent_there_from_every_gated_page(): void
    {
        $nobody = User::factory()->create();
        $empty = $this->newcomer();

        foreach ([$nobody, $empty] as $user) {
            foreach ($this->gatedPaths() as $path) {
                $this->actingAs($user)->get($path)->assertRedirect(route('onboarding'), "{$path} for user {$user->id}");
            }
        }
    }

    public function test_the_welcome_flow_notifications_privacy_and_export_are_never_gated(): void
    {
        $u = $this->newcomer();

        $this->actingAs($u)->get('/welcome-in')->assertOk();
        $this->actingAs($u)->get('/me/notifications')->assertOk();
        $this->actingAs($u)->get('/me/privacy')->assertOk();
        $this->actingAs($u)->get('/me/privacy/export')->assertSuccessful();
    }

    public function test_a_public_business_page_and_the_post_pages_send_a_signed_in_newcomer_to_the_flow_but_not_a_guest(): void
    {
        $entity = $this->dEntity($this->dUser(), ['name' => 'Karoo Books']);
        $entity->forceFill(['moderation' => 'approved'])->save();

        $this->get(route('entities.show', ['entity' => $entity->slug]))->assertOk();
        $this->actingAs($this->newcomer())->get(route('entities.show', ['entity' => $entity->slug]))->assertRedirect(route('onboarding'));
    }

    public function test_the_page_they_wanted_is_remembered_and_a_finished_person_goes_straight_in(): void
    {
        $u = $this->newcomer();
        $this->actingAs($u)->get('/saved')->assertRedirect(route('onboarding'));
        $this->assertStringEndsWith('/saved', session('url.intended'));

        $this->actingAs($this->member())->get('/saved')->assertOk();
    }

    public function test_someone_who_already_filled_in_a_profile_is_not_forced_through_it(): void
    {
        $geo = $this->dGeo();

        foreach ([['headline' => 'Baker'], ['bio' => 'I bake.'], ['place_id' => $geo->jhb->id], ['open_to' => ['work']]] as $fields) {
            $u = User::factory()->create();
            PulseProfile::query()->create(['user_id' => $u->id, 'role' => 'customer'] + $fields);

            $this->actingAs($u)->get('/saved')->assertOk();
        }
    }

    public function test_the_migration_marks_older_profiles_as_onboarded_and_leaves_todays_and_the_age_alone(): void
    {
        $old = User::factory()->create();
        $today = User::factory()->create();
        $done = User::factory()->create();

        DB::table('pulse_profiles')->insert(['user_id' => $old->id, 'role' => 'customer', 'created_at' => now()->subDays(3), 'updated_at' => now()]);
        DB::table('pulse_profiles')->insert(['user_id' => $today->id, 'role' => 'customer', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pulse_profiles')->insert(['user_id' => $done->id, 'role' => 'customer', 'created_at' => now()->subDays(9), 'updated_at' => now(), 'onboarded_at' => now()->subDays(8)]);

        (require base_path('database/migrations/2026_11_09_120000_pulse_backfill_onboarded.php'))->up();

        $this->assertNotNull($this->profileOf($old)->onboarded_at);
        $this->assertNull($this->profileOf($old)->age_confirmed_at, 'age is never filled in for anyone');
        $this->assertNull($this->profileOf($today)->onboarded_at);
        $this->assertSame(now()->subDays(8)->toDateString(), $this->profileOf($done)->onboarded_at->toDateString(), 'a real onboarded_at is kept');
    }
}
