<?php

namespace Tests\Feature\AcceptanceB;

use App\Services\Analytics\AnalyticsClient;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Criterion 13 (suite B): the only external integrations are the real ones, and nothing in the interface pretends
 * to be another. These are guards: they fail the day someone adds an outbound call or a launcher for something that
 * does not exist.
 *
 * Real and used:
 *  - InfoDot sign-in (App\Services\InfoDot\IdentityClient, bearer secret, redeem) and team sync (ProvisionUserFromInfoDot).
 *    Covered by tests/Feature/InfoDotRedeemTest.php, EcosystemSsoPostTest.php and InfoDotTeamSyncTest.php.
 *  - Dot.Analytics publisher (AnalyticsClient): inert without its three settings, HMAC signed over "timestamp.body"
 *    when it runs. Covered by tests/Feature/Analytics/AnalyticsClientTest.php (disabled when url, secret or company is
 *    blank; posts the payload signed with hmac over timestamp dot raw body, with Http::fake).
 *  - An optional model second opinion on moderation (AiModerationService): fails closed, inert without a key.
 */
class IntegrationsAreRealTest extends TestCase
{
    public function test_the_only_code_that_makes_outbound_http_calls_is_the_three_known_clients(): void
    {
        $callers = collect(File::allFiles(app_path()))
            ->filter(fn ($f) => $f->getExtension() === 'php')
            ->filter(fn ($f) => preg_match('/\bHttp::|new\s+\\\\?GuzzleHttp|curl_init|file_get_contents\(\s*[\'"]https?:/', File::get($f->getPathname())) === 1)
            ->map(fn ($f) => str_replace(app_path().DIRECTORY_SEPARATOR, '', $f->getPathname()))
            ->sort()->values()->all();

        $this->assertSame([
            'Services'.DIRECTORY_SEPARATOR.'AiModerationService.php',
            'Services'.DIRECTORY_SEPARATOR.'Analytics'.DIRECTORY_SEPARATOR.'AnalyticsClient.php',
            'Services'.DIRECTORY_SEPARATOR.'InfoDot'.DIRECTORY_SEPARATOR.'IdentityClient.php',
        ], $callers, 'a new outbound integration needs an audit entry in R41_ACCEPTANCE_B.md and a test of how it behaves when the other side is down');
    }

    public function test_the_analytics_publisher_is_inert_without_its_settings(): void
    {
        config(['analytics.url' => '', 'analytics.secret' => '', 'analytics.company_uuid' => '']);

        $this->assertFalse(app(AnalyticsClient::class)->enabled());
    }

    public function test_no_view_links_to_a_site_that_is_not_ours_or_infodots(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (preg_match_all('/href="(https?:\/\/[^"]+)"/', File::get($file->getPathname()), $m)) {
                foreach ($m[1] as $url) {
                    $offenders[] = $file->getRelativePathname().' -> '.$url;
                }
            }
        }

        $this->assertSame([], $offenders, 'literal external links in a view (Laravel starter leftovers, decorative badges)');
    }

    public function test_there_are_no_launchers_for_other_dot_platforms_that_do_not_exist_here(): void
    {
        $this->assertFalse(collect(app('router')->getRoutes()->getRoutes())->contains(fn ($r) => str_starts_with($r->uri(), 'go/')), 'a /go/{platform} launcher is InfoDot\'s; Pulse has none and must not pretend to');

        $names = ['Emall', 'Dot.HR', 'Dot.Mines', 'Dot.Files', 'Dot.Notify', 'Dot.Agents', 'Dot.Brain', 'Dot.Memory', 'Dot.Engage', 'Dot.Press'];
        $found = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $text = File::get($file->getPathname());
            foreach ($names as $name) {
                if (str_contains($text, $name)) {
                    $found[] = $file->getRelativePathname().' mentions '.$name;
                }
            }
        }

        $this->assertSame([], $found, 'nothing visible may claim an integration with a platform Pulse does not call');
    }

    public function test_the_acceptance_sign_in_route_exists_only_locally(): void
    {
        $web = File::get(base_path('routes/web.php'));
        $block = substr($web, (int) strpos($web, '// --- dev-b ---'));

        $this->assertStringContainsString("if (app()->environment('local', 'testing')) {", substr($block, 0, 400), 'the dev-b block must be guarded by the environment check');
        $this->assertStringContainsString('/dev/login-b/{id}', $block);

        // This suite runs as "testing": the route is there. In production the guard above keeps it from being registered.
        $this->assertTrue(collect(app('router')->getRoutes()->getRoutes())->contains(fn ($r) => $r->uri() === 'dev/login-b/{id}'));
    }
}
