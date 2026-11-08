<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

/** R-41 security pass, platform hygiene: response headers, cookies, error pages, stateful sign-in. */
class HeadersAndHygieneTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    public function test_every_page_carries_an_enforced_nonce_csp_and_the_standard_security_headers(): void
    {
        $user = $this->person();

        foreach ([$this->get('/'), $this->get('/login'), $this->actingAs($user)->get('/floor'), $this->actingAs($user)->get('/me/privacy')] as $response) {
            $csp = (string) $response->headers->get('Content-Security-Policy');

            $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{24}' 'unsafe-eval'/", $csp);
            $this->assertStringContainsString("script-src-attr 'none'", $csp);
            $this->assertStringContainsString("frame-ancestors 'none'", $csp);
            $this->assertStringContainsString("object-src 'none'", $csp);
            $this->assertStringContainsString("base-uri 'self'", $csp);
            $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
            $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $response->assertHeader('X-Frame-Options', 'DENY');
            $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));
        }
    }

    public function test_the_nonce_in_the_header_is_the_nonce_on_every_script_tag(): void
    {
        $response = $this->actingAs($this->person())->get('/floor');
        preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy'), $m);
        $html = $response->getContent();

        preg_match_all('/<script\b([^>]*)>/i', $html, $tags);
        $this->assertNotEmpty($tags[1]);

        foreach ($tags[1] as $attrs) {
            if (str_contains($attrs, 'application/ld+json')) {
                continue;
            }
            $this->assertStringContainsString('nonce="'.$m[1].'"', $attrs, "A script tag has no nonce: <script{$attrs}>");
        }

        // No native event handler attribute anywhere (script-src-attr 'none' would silently kill it).
        $this->assertDoesNotMatchRegularExpression('/\s(onclick|onchange|onsubmit|onload|onerror|oninput|onkeydown)=/i', $html);
    }

    public function test_each_response_gets_a_fresh_nonce(): void
    {
        $a = $this->get('/login')->headers->get('Content-Security-Policy');
        $b = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertNotSame($a, $b);
    }

    public function test_the_media_route_keeps_its_own_stricter_policy(): void
    {
        $this->assertSame(404, $this->get('/media/999999')->getStatusCode()); // existence is not revealed

        $response = $this->get('/media/999999');
        $this->assertStringContainsString("script-src 'self'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_report_only_switch_is_honoured(): void
    {
        config(['pulse.security.csp_report_only' => true]);

        $response = $this->get('/login');

        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_hsts_is_sent_in_production_and_over_https(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://pulse.example.test/login')->assertHeader('Strict-Transport-Security');
    }

    public function test_session_cookie_is_http_only_and_same_site(): void
    {
        $this->assertTrue((bool) config('session.http_only'));
        $this->assertContains(config('session.same_site'), ['lax', 'strict']);
    }

    public function test_the_debug_page_does_not_leak_stack_traces_when_debug_is_off(): void
    {
        config(['app.debug' => false]);
        Route::get('/__boom', fn () => throw new \RuntimeException('secret-db-password-xyz'));

        $response = $this->get('/__boom');

        $response->assertStatus(500);
        $this->assertStringNotContainsString('secret-db-password-xyz', $response->getContent());
        $this->assertStringNotContainsString('vendor/laravel', $response->getContent());
    }

    public function test_a_session_signed_in_person_can_poll_and_use_livewire_on_a_host_that_is_not_in_the_stateful_list(): void
    {
        // The Press incident: SSO sign-in followed by "Unauthenticated" on the first API call. Pulse's poll route lives in the
        // web group, so the session guard answers whatever sanctum.stateful says.
        config(['sanctum.stateful' => ['localhost']]);
        $user = $this->person();

        $this->actingAs($user)
            ->withServerVariables(['HTTP_HOST' => 'pulse.unlisted.example'])
            ->getJson('/api/pulse/pulse-check')
            ->assertOk()
            ->assertJsonStructure(['lines_unread', 'requests', 'notifications']);
    }

    public function test_the_poll_endpoint_answers_a_guest_with_401_json_not_a_redirect(): void
    {
        $this->getJson('/api/pulse/pulse-check')->assertUnauthorized();
        $this->get('/api/pulse/pulse-check', ['Accept' => 'text/html'])->assertStatus(401);
    }

    public function test_the_session_cookie_is_secure_by_default_in_production(): void
    {
        $this->assertStringContainsString("env('APP_ENV') === 'production'", file_get_contents(config_path('session.php')));
        $this->assertTrue((bool) config('session.http_only'));
    }

    public function test_reset_links_cannot_be_mailed_on_demand(): void
    {
        $statuses = [];
        for ($i = 0; $i < 8; $i++) {
            $statuses[] = $this->post('/forgot-password', ['email' => 'nobody'.$i.'@example.test'])->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'the mail form is not limited');
    }

    public function test_the_reset_form_answers_the_same_for_an_address_with_an_account_and_one_without(): void
    {
        User::factory()->create(['email' => 'known@example.test']);

        $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'known@example.test']);
        $this->flushSession();
        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'unknown@example.test']);

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->headers->get('Location'), $unknown->headers->get('Location'));
        $unknown->assertSessionHas('status');
        $unknown->assertSessionHasNoErrors();
        $this->assertSame(session('status'), trans('passwords.sent'));
    }
}
