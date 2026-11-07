<?php

namespace Tests\Feature\AcceptanceB;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Found by the browser acceptance run (suite B, criterion 14): "throttle:3,10" on the data export and "throttle:120,1"
 * on the badge poll keyed one counter per person, so the poll used up the export's allowance and "Download my data"
 * answered 429 on the first click.
 */
class ThrottleCountersAreSeparateTest extends TestCase
{
    use RefreshDatabase;

    public function test_polling_does_not_use_up_the_data_export_allowance(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        DB::table('pulse_profiles')->insert(['user_id' => $me->id, 'age_confirmed_at' => now(), 'onboarded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($me)->getJson('/api/pulse/pulse-check')->assertOk();
        }

        $this->actingAs($me)->get('/me/privacy/export')->assertOk()->assertHeader('content-disposition');
    }

    public function test_the_export_is_still_limited_to_three_per_ten_minutes(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        DB::table('pulse_profiles')->insert(['user_id' => $me->id, 'age_confirmed_at' => now(), 'onboarded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        foreach ([1, 2, 3] as $_) {
            $this->actingAs($me)->get('/me/privacy/export')->assertOk();
        }

        $this->actingAs($me)->get('/me/privacy/export')->assertStatus(429);
    }
}
