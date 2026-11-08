<?php

namespace Tests\Feature\E2e;

use App\Services\InfoDot\FakeIdentityClient;
use App\Services\InfoDot\IdentityClient;
use App\Services\InfoDot\InfoDotUnavailable;
use Tests\TestCase;

/** Defects the browser acceptance run (tests/e2e/pulse) found and that no PHP-level test could see. */
class AcceptanceRegressionTest extends TestCase
{
    public function test_a_missing_echo_client_is_not_logged_as_a_console_error(): void
    {
        // Without Reverb credentials bootstrap.js warns once and Pulse polls instead. pulse-realtime.js used to log a
        // console.error on every page in that (normal, daemon-less) setup, which the browser run counts as a failure.
        $js = file_get_contents(resource_path('js/pulse-realtime.js'));

        $this->assertStringNotContainsString('console.error', $js);
        $this->assertStringContainsString("typeof window.Echo === 'undefined'", $js);
    }

    public function test_the_fake_infodot_client_is_never_bound_without_the_e2e_flag_and_never_in_production(): void
    {
        $this->assertNotInstanceOf(FakeIdentityClient::class, app(IdentityClient::class));

        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $this->assertStringContainsString("env('PULSE_E2E_FAKE_INFODOT') === '1'", $provider);
        $this->assertStringContainsString("environment('local', 'testing')", $provider);
    }

    public function test_the_fake_client_redeems_only_its_own_test_tokens(): void
    {
        $fake = new FakeIdentityClient;

        $this->assertNull($fake->redeem('a-real-looking-token'));
        $identity = $fake->redeem('e2e-valid:someone@example.test:Some One:company');
        $this->assertSame('someone@example.test', $identity['user']['email']);
        $this->assertTrue($identity['user']['email_verified']);
        $this->assertCount(2, $identity['teams']);

        $this->expectException(InfoDotUnavailable::class);
        $fake->redeem('e2e-down');
    }
}
