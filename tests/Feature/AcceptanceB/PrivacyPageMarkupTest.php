<?php

namespace Tests\Feature\AcceptanceB;

use App\Models\User;
use App\Services\Pulse\Lines\MuteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Found by the browser acceptance run (suite B, criteria 10 and 14): Blade directives are not compiled inside a
 * component tag, so "Withdraw" and "Unmute" reached the browser with a literal @js(...) handler and threw
 * "Invalid or unexpected token". The page must carry real handlers.
 */
class PrivacyPageMarkupTest extends TestCase
{
    use RefreshDatabase;

    public function test_withdraw_and_unmute_buttons_carry_real_click_handlers(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        DB::table('pulse_profiles')->insert([
            'user_id' => $me->id, 'age_confirmed_at' => now(), 'onboarded_at' => now(), 'contact_policy' => 'anyone',
            'discoverability' => 'open', 'headline' => 'Bookkeeper', 'skills' => json_encode(['bookkeeping']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(MuteService::class)->mute($me, 'topic', 'plumbing');

        $html = $this->actingAs($me)->get('/me/privacy')->assertOk()->getContent();

        $this->assertStringNotContainsString('@js(', $html);
        $this->assertStringContainsString('Unmute', $html);
        $this->assertMatchesRegularExpression('/wire:click="unmute\(&#039;topic&#039;|wire:click="unmute\(\'topic\'|wire:click="unmute\(&quot;topic&quot;|wire:click="unmute\(\\\\u0022topic/', $html);
        $this->assertMatchesRegularExpression('/wire:click="withdraw\(/', $html);
    }
}
