<?php

namespace Tests\Feature\Security;

use App\Models\PulseReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The authorisation sweep. Every route of the application is classified below; a route that is not in the table makes
 * the test fail, so a new route cannot ship without somebody deciding who may reach it. Then the routes that take an id
 * are tried as every kind of person: a guest, a stranger, a blocked person, a member of each role, a participant and a
 * non participant, an under 18 account, the anonymous professional, their would-be employer and a moderator.
 */
class RouteSweepTest extends TestCase
{
    use RefreshDatabase;
    use SecurityWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** Routes that exist on purpose without a sign in, and why. */
    private const PUBLIC = [
        '/', 'login', 'forgot-password', 'reset-password', 'reset-password/{token}', 'two-factor-challenge', 'auth/ecosystem', 'up',
        'b/{entity}', 'media/{mediaFile}/{size?}', 'pulse/digest/unsubscribe/{user}', 'sanctum/csrf-cookie',
        'register', 'storage/{path}', 'livewire/update', 'livewire/upload-file', 'livewire/preview-file/{filename}',
        'livewire/livewire.js', 'livewire/livewire.min.js.map', 'broadcasting/auth', 'dev/kit', 'dev/floor', 'dev/login/{who}', 'dev/logout', 'dev/login/{who}', 'dev/logout', '_boost/browser-logs',
    ];

    public function test_every_route_is_classified_and_everything_unclassified_needs_a_sign_in(): void
    {
        $unclassified = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $middleware = $route->gatherMiddleware();

            $guarded = collect($middleware)->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'auth') || str_starts_with($m, 'Illuminate\\Auth\\Middleware\\Authenticate') || str_contains($m, 'Authenticate')));

            if (in_array($uri, self::PUBLIC, true) || $guarded) {
                continue;
            }

            if (str_starts_with($uri, 'user/') || str_starts_with($uri, '_ignition')) {
                continue;
            }

            $unclassified[] = implode('|', $route->methods()).' '.$uri;
        }

        $this->assertSame([], $unclassified, 'These routes are reachable without signing in and are not in the public list: '.implode(', ', $unclassified));
    }

    public function test_dev_pages_and_the_boost_logger_are_not_registered_in_production(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertMatchesRegularExpression("/environment\\('local', 'testing'\\)\\) \\{\\s*Route::view\\('\\/dev\\/kit'/", $routes);

        $boost = base_path('vendor/laravel/boost/src/BoostServiceProvider.php');
        if (is_file($boost)) {
            $source = file_get_contents($boost);
            $this->assertStringContainsString('local', $source, 'Boost registers its browser log route only for local environments');
        }
    }

    public function test_guests_are_sent_to_sign_in_from_every_signed_in_page(): void
    {
        $w = fn ($k) => $this->w($k);

        $urls = [
            '/floor', '/trade', '/work', '/local', '/learn', '/search', '/saved', '/door', '/lines', '/community', '/moderation', '/operator', '/dashboard',
            '/lines/'.$w('line')->id, '/lines/quotes/'.\Illuminate\Support\Str::uuid(), '/s/'.$w('talentSignal')->id,
            '/b/'.$w('seller')->slug.'/manage', '/b/'.$w('seller')->slug.'/manage/team', '/b/'.$w('seller')->slug.'/o/'.$w('offer')->slug,
            '/b/'.$w('seller')->slug.'/r/'.$w('role')->slug, '/need/'.$w('need')->id, '/need/'.$w('need')->id.'/edit', '/need/new', '/business/new',
            '/u/'.$w('stranger')->id, '/me', '/me/profile', '/me/discoverability', '/me/settings', '/me/notifications', '/me/privacy', '/me/privacy/export',
            '/welcome-in', '/posts/1', '/c/anything',
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    /** @return array<string, array{0: string, 1: array<string, int>}> url template => actor => status */
    public function test_resource_pages_answer_each_kind_of_person_correctly(): void
    {
        $w = fn ($k) => $this->w($k);
        $seller = $w('seller')->slug;

        // 404 (never 403) where the page must not confirm that something exists; 403 where the person is a member who may not do that.
        $matrix = [
            ['/lines/'.$w('line')->id, [
                'stranger' => 200, 'sellerOwner' => 200, 'sellerAdmin' => 200, 'sellerResponder' => 200, 'sellerEditor' => 404,
                'blocked' => 404, 'minor' => 404, 'needOwner' => 404, 'employerOwner' => 404, 'pro' => 404, 'moderator' => 404,
            ]],
            ['/lines/'.$w('invite')->id, [
                'employerOwner' => 200, 'pro' => 200, 'stranger' => 404, 'sellerOwner' => 404, 'moderator' => 404, 'minor' => 404,
            ]],
            ['/b/'.$seller.'/manage', [
                'sellerOwner' => 200, 'sellerAdmin' => 200, 'sellerEditor' => 200, 'sellerResponder' => 200, 'stranger' => 404, 'blocked' => 404, 'employerOwner' => 404, 'moderator' => 404,
            ]],
            ['/b/'.$seller.'/manage/page', ['sellerOwner' => 200, 'sellerAdmin' => 200, 'sellerEditor' => 403, 'sellerResponder' => 403, 'stranger' => 404]],
            ['/b/'.$seller.'/manage/verify', ['sellerOwner' => 200, 'sellerAdmin' => 200, 'sellerEditor' => 403, 'sellerResponder' => 403, 'stranger' => 404]],
            ['/b/'.$seller.'/manage/team', ['sellerOwner' => 200, 'sellerAdmin' => 200, 'sellerEditor' => 403, 'sellerResponder' => 403, 'stranger' => 404]],
            ['/b/'.$seller.'/manage/offers', ['sellerOwner' => 200, 'sellerAdmin' => 200, 'sellerEditor' => 200, 'sellerResponder' => 403, 'stranger' => 404]],
            ['/b/'.$seller.'/manage/roles', ['sellerOwner' => 200, 'sellerAdmin' => 200, 'sellerEditor' => 200, 'sellerResponder' => 403, 'stranger' => 404]],
            ['/b/'.$seller.'/manage/lines', ['sellerOwner' => 200, 'sellerAdmin' => 200, 'sellerResponder' => 200, 'sellerEditor' => 403, 'stranger' => 404]],
            ['/b/'.$seller.'/o/'.$w('offer')->slug, ['stranger' => 200, 'blocked' => 200, 'sellerResponder' => 200]],
            ['/b/'.$seller.'/o/'.$w('heldOffer')->slug, ['stranger' => 404, 'blocked' => 404, 'employerOwner' => 404, 'sellerEditor' => 200, 'sellerOwner' => 200, 'moderator' => 200]],
            ['/b/'.$seller.'/o/'.$w('draftOffer')->slug, ['stranger' => 404, 'sellerEditor' => 200]],
            ['/b/'.$seller.'/r/'.$w('role')->slug, ['stranger' => 200]],
            ['/b/'.$seller.'/r/'.$w('heldRole')->slug, ['stranger' => 404, 'sellerEditor' => 200]],
            ['/need/'.$w('need')->id, ['needOwner' => 200, 'stranger' => 200, 'blocked' => 200, 'moderator' => 200]],
            ['/need/'.$w('heldNeed')->id, ['needOwner' => 200, 'stranger' => 404, 'moderator' => 200]],
            ['/need/'.$w('need')->id.'/edit', ['needOwner' => 200, 'stranger' => 404, 'moderator' => 404, 'sellerOwner' => 404]],
            ['/need/'.$w('heldNeed')->id.'/edit', ['needOwner' => 200, 'stranger' => 404]],
            ['/moderation', ['stranger' => 403, 'sellerOwner' => 403, 'moderator' => 200]],
            ['/operator', ['stranger' => 403, 'sellerOwner' => 403, 'moderator' => 200]],
            ['/u/'.$w('pro')->id, ['stranger' => 404, 'employerOwner' => 404, 'moderator' => 404]], // an anonymous professional's page does not exist for anyone else
            ['/lines/quotes/'.\Illuminate\Support\Str::uuid(), ['stranger' => 404, 'sellerOwner' => 404]],
        ];

        $failures = [];

        foreach ($matrix as [$url, $expect]) {
            foreach ($expect as $actor => $status) {
                $response = $this->actingAs($this->w($actor)->fresh())->get($url);
                $got = $response->getStatusCode();

                if ($got !== $status) {
                    $failures[] = "{$actor} GET {$url}: expected {$status}, got {$got}";
                }

                $this->flushSession();
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_signal_detail_is_only_for_people_the_signal_is_eligible_for(): void
    {
        $signal = $this->w('talentSignal');

        // Only employer pages can find talent; a stranger and a seller cannot, and the pro cannot open their own as a beat.
        $this->actingAs($this->w('stranger'))->get('/s/'.$signal->id)->assertNotFound();
        $this->actingAs($this->w('sellerOwner'))->get('/s/'.$signal->id)->assertNotFound();
        $this->actingAs($this->w('moderator'))->get('/s/'.$signal->id)->assertNotFound();

        // Held, removed, paused and expired signals are gone for everyone but... nobody.
        foreach (['held', 'removed', 'paused', 'expired'] as $status) {
            $signal->forceFill(['status' => $status])->save();
            $this->actingAs($this->w('employerOwner'))->get('/s/'.$signal->id)->assertNotFound();
        }
    }

    public function test_public_business_page_gives_a_guest_only_the_limited_card(): void
    {
        $w = fn ($k) => $this->w($k);
        $seller = $w('seller');
        $seller->forceFill(['public_email' => 'private-owner@example.test', 'public_phone' => '0821234567'])->save();

        $html = $this->get('/b/'.$seller->slug)->assertOk()->getContent();

        $this->assertStringContainsString('Moyo Plumbing Works', $html);
        $this->assertStringNotContainsString('private-owner@example.test', $html);
        $this->assertStringNotContainsString('0821234567', $html);
        $this->assertStringNotContainsString($w('sellerOwner')->email, $html);
        $this->assertStringNotContainsString($w('sellerOwner')->name, $html);
        $this->assertStringNotContainsString('Held offer text', $html);
        $this->assertStringNotContainsString('Draft offer text', $html);
        $this->assertStringNotContainsString('Held bookkeeper role', $html);
    }

    public function test_a_page_that_is_archived_suspended_or_removed_is_a_404_to_the_public_and_members_still_reach_it(): void
    {
        $seller = $this->w('seller');

        foreach ([['status' => 'archived'], ['status' => 'suspended'], ['moderation' => 'removed'], ['moderation' => 'pending']] as $change) {
            $seller->forceFill(array_merge(['status' => 'live', 'moderation' => 'approved'], $change))->save();

            $label = json_encode($change);
            $this->app['auth']->forgetGuards();
            $this->assertSame(404, $this->get('/b/'.$seller->slug)->getStatusCode(), "guest /b {$label}");
            $this->assertSame(404, $this->actingAs($this->w('stranger'))->get('/b/'.$seller->slug)->getStatusCode(), "stranger /b {$label}");
            $this->assertSame(404, $this->actingAs($this->w('stranger'))->get('/b/'.$seller->slug.'/o/'.$this->w('offer')->slug)->getStatusCode(), "stranger offer {$label}");
            $this->assertSame(200, $this->actingAs($this->w('sellerOwner'))->get('/b/'.$seller->slug)->getStatusCode(), "owner /b {$label}");
            $this->flushSession();
        }
    }

    public function test_a_moderator_reads_a_line_only_while_a_report_about_it_is_open(): void
    {
        $line = $this->w('line');
        $mod = $this->w('moderator');

        $this->actingAs($mod)->get('/lines/'.$line->id)->assertNotFound();

        PulseReport::query()->create(['reporter_id' => $this->w('stranger')->id, 'reportable_type' => $line::class, 'reportable_id' => $line->id, 'reason' => 'spam', 'status' => 'open']);

        $this->actingAs($mod)->get('/lines/'.$line->id)->assertOk();
    }

    public function test_a_removed_member_loses_the_page_at_once(): void
    {
        $responder = $this->w('sellerResponder');
        $this->actingAs($responder)->get('/b/'.$this->w('seller')->slug.'/manage/lines')->assertOk();

        \Illuminate\Support\Facades\DB::table('pulse_entity_members')->where('user_id', $responder->id)->delete();

        $this->actingAs($responder->fresh())->get('/b/'.$this->w('seller')->slug.'/manage/lines')->assertNotFound();
        $this->actingAs($responder->fresh())->get('/lines/'.$this->w('line')->id)->assertNotFound();
    }

    public function test_scraping_the_public_business_page_is_throttled_and_robots_keeps_crawlers_out_of_the_rest(): void
    {
        $slug = $this->w('seller')->slug;
        $last = 200;
        for ($i = 0; $i < 125; $i++) {
            $last = $this->get('/b/'.$slug)->getStatusCode();
            if ($last === 429) {
                break;
            }
        }
        $this->assertSame(429, $last);

        $robots = file_get_contents(public_path('robots.txt'));
        foreach (['/media/', '/lines/', '/me/', '/api/', '/u/', '/s/'] as $path) {
            $this->assertStringContainsString('Disallow: '.$path, $robots);
        }
        $this->assertDoesNotMatchRegularExpression('~^Disallow: /b/\\s*$~m', $robots, 'the public business page stays crawlable');
    }
}
