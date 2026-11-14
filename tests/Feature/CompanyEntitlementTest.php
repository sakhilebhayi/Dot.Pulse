<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\PulseProfile;
use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\EntitlementClient;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

/**
 * R-50: Dot.Pulse honours the company's entitlement. At sign-in only companies InfoDot marks entitled are admitted;
 * during a session the signed-in area asks InfoDot whether the current company still holds the platform. A clear
 * "no" is the plain 403 page; every failure lets the person carry on. Individuals hold the free core only: a person
 * with no company that holds Pulse is refused afterwards, with their data untouched.
 */
class CompanyEntitlementTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private const PERSONAL = '44444444-4444-4444-8444-444444444444';

    private const HEADLINE = 'Distinctive headline that only the owner may read';

    private string $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credential = Str::random(40);

        config(['ecosystem.infodot' => [
            'redeem_url' => self::REDEEM, 'platform' => 'pulse', 'secret' => $this->credential, 'timeout' => 2, 'provision' => true,
            'entitlement_check' => true,
        ]]);
        config(['ecosystem.platforms.infodot.url' => 'https://infodot.test']);
    }

    // ------------------------------------------------------------------ helpers

    private static function readUrl(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** The read endpoint's body, as InfoDot's own endpoint tests assert it. */
    private static function platforms(string $uuid, bool $entitled, string $status): array
    {
        return [
            'platform' => 'pulse',
            'company' => ['uuid' => $uuid, 'personal' => false],
            'platforms' => [
                ['key' => 'finance', 'name' => 'Dot.Finance', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'free_while_pricing_finalised', 'entitled' => false, 'is_core' => false],
                ['key' => 'infodot', 'name' => 'InfoDot', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'pulse', 'name' => 'Dot.Pulse', 'status' => $status, 'entitled' => $entitled, 'is_core' => false, 'access_mode' => 'everyone'],
            ],
        ];
    }

    private function infodotSays(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /** @return array{0: User, 1: Team} an onboarded owner with a headline, and their InfoDot company (current) */
    private function company(string $uuid = self::ACME, string $name = 'Acme Ltd', ?User $owner = null): array
    {
        if (! $owner) {
            $owner = $this->pulseUser();
            PulseProfile::where('user_id', $owner->id)->update(['headline' => self::HEADLINE]);
        }

        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        return [$owner->fresh(), $team];
    }

    private function fromInfodot(): array
    {
        return ['Origin' => 'https://infodot.test'];
    }

    private function redeemTeam(string $uuid, string $name, string $role, ?bool $entitled = null, ?string $access = null, bool $personal = false): array
    {
        $team = ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => $personal, 'role' => $role];

        return $entitled === null ? $team : $team + ['entitled' => $entitled, 'access' => $access];
    }

    private function signIn(string $email, array $teams, ?string $current = null)
    {
        $this->infodotSays([self::REDEEM => Http::response([
            'platform' => 'pulse',
            'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
            'current_team_uuid' => $current,
        ])]);

        auth()->logout();

        return $this->post(route('ecosystem.auth'), ['token' => 'one-time'], $this->fromInfodot());
    }

    private function settingsOf(User $user): ?string
    {
        return PulseProfile::where('user_id', $user->id)->value('contact_policy');
    }

    // ------------------------------------------------------------------ sign-in (redeem)

    public function test_a_company_marked_entitled_is_admitted_at_sign_in(): void
    {
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', true)], self::ACME)->assertRedirect(route('floor'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(Team::where('infodot_team_uuid', self::ACME)->firstOrFail()->id, $user->current_team_id);
    }

    public function test_a_company_marked_not_entitled_is_not_created_updated_or_made_current(): void
    {
        [, $globex] = $this->company(self::GLOBEX, 'Globex (old name)');
        $ann = User::factory()->withPersonalTeam()->create(['email' => 'ann@acme.test']);
        $globex->users()->attach($ann->id, ['role' => 'viewer']);
        $ann->forceFill(['current_team_id' => $globex->id])->save();

        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', true),
            $this->redeemTeam(self::GLOBEX, 'Globex Corporation', 'admin', false, 'suspended'),
            $this->redeemTeam('33333333-3333-4333-8333-333333333333', 'Initech', 'owner', false, 'not_activated'),
        ], self::GLOBEX)->assertRedirect(route('floor'));

        $ann->refresh();
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        $this->assertNull(Team::where('infodot_team_uuid', '33333333-3333-4333-8333-333333333333')->first());
        $this->assertSame('Globex (old name)', $globex->fresh()->name);
        $this->assertSame('viewer', $globex->users()->where('users.id', $ann->id)->first()->membership->role);
        $this->assertTrue($ann->belongsToTeam($globex));
        $this->assertSame($acme->id, $ann->current_team_id);
    }

    public function test_without_the_key_sign_in_behaves_as_before(): void
    {
        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner'),
            $this->redeemTeam(self::GLOBEX, 'Globex', 'admin'),
        ], self::GLOBEX)->assertRedirect(route('floor'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->assertNotNull(Team::where('infodot_team_uuid', self::ACME)->first());
        $this->assertSame(Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail()->id, $user->current_team_id);
    }

    public function test_when_no_company_is_entitled_the_person_gets_the_plain_page_and_is_not_signed_in(): void
    {
        $response = $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::PERSONAL, "Ann's Team", 'owner', false, 'no_personal_access', personal: true),
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', false, 'payment_required'),
        ], self::ACME);

        $response->assertStatus(403)
            ->assertSee('Dot.Pulse needs a payment for Acme Ltd')
            ->assertSee('href="https://infodot.test/platforms/pulse"', false)
            ->assertSee('Open Platforms in InfoDot')
            ->assertDontSee('Whoops')
            ->assertDontSee('Stack trace');

        $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'));
        $this->assertGuest();
        $this->assertNull(User::where('email', 'ann@acme.test')->first());
        $this->assertSame(0, Team::whereNotNull('infodot_team_uuid')->count());
    }

    public function test_the_page_escapes_the_company_name(): void
    {
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::ACME, '<script>alert(1)</script>', 'owner', false, 'not_activated')], self::ACME)
            ->assertStatus(403)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    // ------------------------------------------------------------------ during a session

    public function test_an_entitled_company_carries_on(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('me.profile'))->assertOk()->assertSee(self::HEADLINE);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://infodot.test/api/ecosystem/companies/'.self::ACME.'/platforms')
            && $request['platform'] === 'pulse'
            && $request->hasHeader('X-Dot-Platform', 'pulse')
            && $request->hasHeader('Authorization', 'Bearer '.$this->credential));
    }

    public static function refusals(): array
    {
        return [
            'not switched on' => ['free_while_pricing_finalised', 'Dot.Pulse is not switched on for Acme Ltd'],
            'suspended' => ['suspended', 'Dot.Pulse is suspended for Acme Ltd'],
            'payment needed' => ['payment_required', 'Dot.Pulse needs a payment for Acme Ltd'],
            'ended' => ['subscription_expired', 'Dot.Pulse has ended for Acme Ltd'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_company_that_no_longer_holds_the_platform_gets_403_and_no_data(string $status, string $title): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, $status))]);

        foreach ([route('floor'), route('me.profile'), route('me.settings'), route('community.feed'), route('lines'), route('onboarding')] as $url) {
            $response = $this->actingAs($owner)->get($url)->assertStatus(403)
                ->assertSee($title)
                ->assertSee('href="https://infodot.test/platforms/pulse"', false)
                ->assertDontSee(self::HEADLINE);

            $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'), $url);
        }

        // A JSON call (an upload, a broadcasting auth) gets a bare 403.
        $this->actingAs($owner)->postJson('/broadcasting/auth', ['channel_name' => 'private-x', 'socket_id' => '1.1'])->assertStatus(403);

        $this->assertSame(self::HEADLINE, PulseProfile::where('user_id', $owner->id)->value('headline'), 'the data is untouched');
    }

    public static function failures(): array
    {
        return [
            'bad secret (401)' => [fn () => Http::response(['error' => 'invalid_request'], 401)],
            'platform switched off (403)' => [fn () => Http::response(['error' => 'invalid_request'], 403)],
            'unknown company (404)' => [fn () => Http::response(['error' => 'not_found'], 404)],
            'throttled (429)' => [fn () => Http::response('', 429)],
            'server error (500)' => [fn () => Http::response('boom', 500)],
            'not json' => [fn () => Http::response('<html>maintenance</html>', 200)],
            'no platforms list' => [fn () => Http::response(['platform' => 'pulse', 'company' => ['uuid' => self::ACME, 'personal' => false]])],
            'own row missing' => [fn () => Http::response(['platform' => 'pulse', 'company' => ['uuid' => self::ACME, 'personal' => false], 'platforms' => [
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'suspended', 'entitled' => false, 'is_core' => false],
            ]])],
            'entitled is not a boolean' => [fn () => Http::response(['platform' => 'pulse', 'platforms' => [['key' => 'pulse', 'status' => 'suspended', 'entitled' => 'false']]])],
            'answer for another platform' => [fn () => Http::response(['platform' => 'hr', 'platforms' => [['key' => 'pulse', 'status' => 'suspended', 'entitled' => false]]])],
            'connection error or timeout' => [fn () => fn () => throw new ConnectionException('timed out')],
        ];
    }

    #[DataProvider('failures')]
    public function test_any_failure_lets_the_person_carry_on_and_is_logged_once(Closure $answer): void
    {
        [$owner] = $this->company();
        $calls = 0;
        $reply = $answer();
        $this->infodotSays([self::readUrl(self::ACME) => function () use (&$calls, $reply) {
            $calls++;

            return $reply instanceof Closure ? $reply() : $reply;
        }]);
        Log::spy();

        $this->actingAs($owner)->get(route('me.profile'))->assertOk()->assertSee(self::HEADLINE);
        $this->actingAs($owner)->get(route('floor'))->assertOk();

        $this->assertSame(1, $calls, 'one call for the cache window');
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'InfoDot entitlement check gave no answer'));
    }

    public function test_a_failure_is_remembered_for_a_minute_and_an_answer_for_ten(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::sequence()
            ->push('boom', 500)
            ->push(self::platforms(self::ACME, false, 'suspended'))
            ->push(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('floor'))->assertOk();

        $this->travel(EntitlementClient::FAILURE_TTL + 1)->seconds();
        $this->actingAs($owner)->get(route('floor'))->assertStatus(403);

        $this->travel(EntitlementClient::ANSWER_TTL - 5)->seconds();
        $this->actingAs($owner)->get(route('floor'))->assertStatus(403);
        Http::assertSentCount(2);

        $this->travel(10)->seconds();
        $this->actingAs($owner)->get(route('floor'))->assertOk();
        Http::assertSentCount(3);
    }

    public function test_no_secret_configured_carries_on_without_calling(): void
    {
        [$owner] = $this->company();
        config(['ecosystem.infodot.secret' => null]);
        $this->infodotSays([]);

        $this->actingAs($owner)->get(route('floor'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_answer_is_cached_per_company(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('floor'))->assertOk();
        $this->actingAs($owner)->get(route('me.profile'))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_with_the_flag_off_no_call_is_made(): void
    {
        [$owner] = $this->company();
        config(['ecosystem.infodot.entitlement_check' => false]);
        $this->infodotSays([]);

        $this->actingAs($owner)->get(route('floor'))->assertOk();
        $this->actingAs($owner)->get(route('me.profile'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_flag_is_on_by_default(): void
    {
        $this->assertTrue((require config_path('ecosystem.php'))['infodot']['entitlement_check']);
        $this->assertStringContainsString('PULSE_ENTITLEMENT_CHECK=true', file_get_contents(base_path('.env.example')));
    }

    public function test_exempt_routes_make_no_call(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([]);

        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/up')->assertOk();
        $this->get('/auth/ecosystem')->assertRedirect('/login');

        $this->actingAs($owner);
        $this->get(route('profile.show'))->assertRedirect(route('me.profile'));
        $this->get(route('me.export'))->assertOk();
        $this->post(route('logout'))->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_a_second_company_that_is_entitled_can_still_be_switched_to(): void
    {
        [$owner, $acme] = $this->company();
        [, $globex] = $this->company(self::GLOBEX, 'Globex Corporation', $owner);
        $owner->forceFill(['current_team_id' => $acme->id])->save();

        $this->infodotSays([
            self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended')),
            self::readUrl(self::GLOBEX) => Http::response(self::platforms(self::GLOBEX, true, 'active')),
        ]);

        $this->actingAs($owner->fresh())->get(route('floor'))->assertStatus(403)
            ->assertSee('Globex Corporation')
            ->assertSee('action="'.route('current-team.update').'"', false)
            ->assertSee('name="team_id" value="'.$globex->id.'"', false);

        $this->put(route('current-team.update'), ['team_id' => $globex->id])->assertRedirect();
        $this->assertSame($globex->id, $owner->fresh()->current_team_id);

        $this->actingAs($owner->fresh())->get(route('floor'))->assertOk();
    }

    public function test_the_check_is_on_every_route_group_and_the_livewire_persistent_list(): void
    {
        $this->assertContains(EnsureCompanyEntitled::class, app(PersistentMiddleware::class)->getPersistentMiddleware());

        foreach (['floor', 'me.profile', 'onboarding', 'lines', 'entities.create', 'operator', 'me.privacy'] as $name) {
            $this->assertContains(EnsureCompanyEntitled::class, app('router')->getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }

        $broadcast = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'broadcasting/auth');
        $this->assertContains(EnsureCompanyEntitled::class, $broadcast->gatherMiddleware());
        $this->assertContains(EnsureCompanyEntitled::class, (array) config('livewire.temporary_file_upload.middleware'));
    }

    // ------------------------------------------------------------------ individuals hold the free core only

    /** Ann signs in with one entitled company and a personal workspace, then works in her personal team. */
    private function signInThenWorkPersonally(?bool $personalEntitled, bool $onboarded = true): User
    {
        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::PERSONAL, "Ann's Team", 'owner', $personalEntitled, $personalEntitled === false ? 'no_personal_access' : null, personal: true),
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', $personalEntitled === null ? null : true),
        ], self::ACME)->assertRedirect(route('floor'));

        // The test client does not carry the session between requests; hand over what sign-in remembered.
        $this->withSession(session()->has(EnsureCompanyEntitled::PERSONAL) ? [EnsureCompanyEntitled::PERSONAL => session(EnsureCompanyEntitled::PERSONAL)] : []);

        $ann = User::where('email', 'ann@acme.test')->firstOrFail();
        PulseProfile::where('user_id', $ann->id)->update($onboarded ? ['headline' => self::HEADLINE, 'onboarded_at' => now()->subDay()] : ['onboarded_at' => null, 'headline' => null]);
        $ann->forceFill(['current_team_id' => $ann->personalTeam()->id])->save();

        $this->actingAs($ann->fresh());

        return $ann->fresh();
    }

    public function test_a_person_with_no_company_that_holds_pulse_is_refused_and_their_data_is_untouched(): void
    {
        $ann = $this->signInThenWorkPersonally(false);
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $this->assertFalse(session(EnsureCompanyEntitled::PERSONAL));
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        foreach ([route('floor'), route('me.profile'), route('me.settings'), route('lines')] as $url) {
            $response = $this->get($url)->assertStatus(403)
                ->assertSee('Dot.Pulse needs a company that has switched it on')
                ->assertSee('href="https://infodot.test/platforms/pulse"', false)
                ->assertSee('name="team_id" value="'.$acme->id.'"', false)
                ->assertDontSee(self::HEADLINE);

            $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'), $url);
        }

        Http::assertNothingSent();

        $this->assertSame(self::HEADLINE, PulseProfile::where('user_id', $ann->id)->value('headline'));
        $this->get(route('profile.show'))->assertRedirect(route('me.profile'));

        // The entitled company can still be switched to, and then the person is in again.
        $this->put(route('current-team.update'), ['team_id' => $acme->id])->assertRedirect();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);
        $this->get(route('floor'))->assertOk();
    }

    public function test_the_welcome_flow_follows_the_same_rule_with_no_redirect_loop(): void
    {
        $this->signInThenWorkPersonally(false, onboarded: false);
        $this->infodotSays([]);

        // A page that would send a newcomer to the welcome flow is refused first, and so is the flow itself.
        $this->get(route('floor'))->assertStatus(403)->assertSee('Dot.Pulse needs a company that has switched it on');
        $this->get(route('onboarding'))->assertStatus(403)->assertSee('Dot.Pulse needs a company that has switched it on');
        $this->get(route('me.settings'))->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_a_newcomer_whose_personal_workspace_is_entitled_still_goes_through_the_welcome_flow(): void
    {
        $this->signInThenWorkPersonally(true, onboarded: false);
        $this->infodotSays([]);

        $this->get(route('floor'))->assertRedirect(route('onboarding'));
        $this->get(route('onboarding'))->assertOk();
    }

    public function test_a_team_with_no_infodot_company_is_refused_the_same_way(): void
    {
        $ann = $this->signInThenWorkPersonally(false);
        $local = Team::forceCreate(['user_id' => $ann->id, 'name' => 'Local Co', 'personal_team' => false]);
        $ann->forceFill(['current_team_id' => $local->id])->save();
        $this->infodotSays([]);

        $this->get(route('floor'))->assertStatus(403)->assertSee('Dot.Pulse needs a company that has switched it on');
        Http::assertNothingSent();
    }

    public function test_a_personal_workspace_infodot_marked_entitled_carries_on(): void
    {
        $this->signInThenWorkPersonally(true);
        $this->assertTrue(session(EnsureCompanyEntitled::PERSONAL));
        $this->infodotSays([]);

        $this->get(route('floor'))->assertOk();
        $this->get(route('me.profile'))->assertOk()->assertSee(self::HEADLINE);
        Http::assertNothingSent();
    }

    public function test_with_no_key_at_sign_in_nothing_is_remembered_and_the_personal_workspace_carries_on(): void
    {
        $this->signInThenWorkPersonally(null);
        $this->assertFalse(session()->has(EnsureCompanyEntitled::PERSONAL));
        $this->infodotSays([]);

        $this->get(route('floor'))->assertOk();
        $this->get(route('me.profile'))->assertOk()->assertSee(self::HEADLINE);
        Http::assertNothingSent();
    }

    public function test_a_later_sign_in_without_the_key_forgets_an_earlier_refusal(): void
    {
        $this->signInThenWorkPersonally(false);
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::PERSONAL, "Ann's Team", 'owner', personal: true), $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner')], self::ACME)->assertRedirect();

        $this->assertFalse(session()->has(EnsureCompanyEntitled::PERSONAL));
    }

    public function test_the_personal_refusal_is_off_with_the_flag(): void
    {
        $this->signInThenWorkPersonally(false);
        config(['ecosystem.infodot.entitlement_check' => false]);

        $this->get(route('floor'))->assertOk();
    }

    // ------------------------------------------------------------------ live updates

    /** The Livewire snapshot of one component, read off a real page response. */
    private function snapshotOf(string $url, string $component): string
    {
        $page = $this->get($url)->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $page, $all);

        foreach ($all[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES);

            if (str_contains($snapshot, '"name":"'.$component.'"')) {
                return $snapshot;
            }
        }

        $this->fail("component {$component} is not on {$url}");
    }

    /** A real Livewire update request (the test harness skips persistent middleware): the contact policy is set. */
    private function livewireSetPolicy(string $snapshot, string $policy)
    {
        return $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => ['contactPolicy' => $policy],
            'calls' => [],
        ]]], ['X-Livewire' => 'true']);
    }

    public function test_a_livewire_update_is_refused_once_the_company_loses_the_platform(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::sequence()
            ->push(self::platforms(self::ACME, true, 'active'))
            ->push(self::platforms(self::ACME, false, 'suspended'))]);
        $this->actingAs($owner);
        $snapshot = $this->snapshotOf(route('me.settings'), 'pulse.me.settings');
        $before = $this->settingsOf($owner);
        $this->assertNotSame('nobody', $before);

        // While the company holds the platform the action works.
        $worked = $this->livewireSetPolicy($snapshot, 'nobody');
        $this->assertSame(200, $worked->getStatusCode());
        $this->assertSame('nobody', $this->settingsOf($owner));

        // Reset, let the cached answer expire, and InfoDot now says no: the same action is refused.
        PulseProfile::where('user_id', $owner->id)->update(['contact_policy' => $before]);
        $this->travel(EntitlementClient::ANSWER_TTL + 1)->seconds();
        $response = $this->livewireSetPolicy($snapshot, 'nobody');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('"components"', $response->getContent());
        $this->assertSame($before, $this->settingsOf($owner), 'the data is unchanged');
    }

    public function test_a_livewire_update_is_refused_for_a_personal_workspace_the_rule_refuses(): void
    {
        $ann = $this->signInThenWorkPersonally(true);
        $this->infodotSays([]);
        $snapshot = $this->snapshotOf(route('me.settings'), 'pulse.me.settings');
        $before = $this->settingsOf($ann);

        // The rule changes mid-session (the remembered answer is now false): updates stop too.
        session([EnsureCompanyEntitled::PERSONAL => false]);
        $this->withSession([EnsureCompanyEntitled::PERSONAL => false]);
        $response = $this->livewireSetPolicy($snapshot, 'nobody');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('"components"', $response->getContent());
        $this->assertSame($before, $this->settingsOf($ann));
    }

    // ------------------------------------------------------------------ data-subject rights are never blocked

    /** A refused person (company suspended) with an open privacy page: owner, snapshot of the privacy component. */
    private function refusedOnPrivacyPage(): array
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended'))]);
        $this->actingAs($owner);
        $this->get(route('floor'))->assertStatus(403);

        return [$owner, $this->snapshotOf(route('me.privacy'), 'pulse.me.privacy')];
    }

    /** @param array<string, mixed> $updates @param list<string> $calls */
    private function privacyUpdate(string $snapshot, array $updates, array $calls)
    {
        return $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => array_map(fn ($m) => ['path' => '', 'method' => $m, 'params' => $m === 'withdraw' ? ['contact_policy.requests'] : []], $calls),
        ]]], ['X-Livewire' => 'true']);
    }

    public function test_a_refused_person_can_still_open_the_privacy_page_and_export(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended'))]);
        $this->actingAs($owner);

        $this->get(route('floor'))->assertStatus(403);
        $this->get(route('me.privacy'))->assertOk()->assertSee('Download your data');

        $snapshot = $this->snapshotOf(route('me.privacy'), 'pulse.me.privacy');
        $response = $this->privacyUpdate($snapshot, [], ['startExport']);
        $this->assertSame(200, $response->getStatusCode());

        $response = $this->privacyUpdate($snapshot, ['confirmation' => 'download my data'], ['export']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('me\/privacy\/export', $response->getContent(), 'the export confirmation redirects to the download');

        $this->get(route('me.export'))->assertOk();
    }

    public function test_a_refused_person_can_still_erase_and_the_confirmations_still_apply(): void
    {
        [$owner, $snapshot] = $this->refusedOnPrivacyPage();

        // A wrong phrase erases nothing.
        $this->privacyUpdate($snapshot, ['confirmation' => 'erase'], ['erase'])->assertOk();
        $this->assertSame(self::HEADLINE, PulseProfile::where('user_id', $owner->id)->value('headline'));
        $this->assertAuthenticated();

        // The typed phrase erases, exactly as for anyone else.
        $response = $this->privacyUpdate($snapshot, ['confirmation' => 'erase my data'], ['erase']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(PulseProfile::where('user_id', $owner->id)->value('headline'));
        $this->assertNull(PulseProfile::where('user_id', $owner->id)->value('onboarded_at'), 'they begin again if they return');
    }

    public function test_the_privacy_exemption_opens_nothing_else(): void
    {
        [$owner, $snapshot] = $this->refusedOnPrivacyPage();
        PulseProfile::where('user_id', $owner->id)->update(['contact_policy' => 'anyone']);

        // Withdrawing a setting from the same page is refused and changes nothing.
        $response = $this->privacyUpdate($snapshot, [], ['withdraw']);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('anyone', $this->settingsOf($owner));

        // So is a mixed request: an allowed step does not carry a forbidden one.
        $this->assertSame(403, $this->privacyUpdate($snapshot, [], ['startErase', 'withdraw'])->getStatusCode());
        $this->assertSame(403, $this->privacyUpdate($snapshot, ['placeId' => 3], ['startErase'])->getStatusCode());

        // Every other page stays refused.
        $this->get(route('me.settings'))->assertStatus(403);
        $this->get(route('floor'))->assertStatus(403);
    }

    public function test_a_refused_company_does_not_offer_a_personal_workspace_the_rule_also_refuses(): void
    {
        $ann = $this->signInThenWorkPersonally(false);
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $ann->forceFill(['current_team_id' => $acme->id])->save();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended'))]);

        $personal = $ann->fresh()->personalTeam();
        $this->actingAs($ann->fresh());

        $this->get(route('floor'))->assertStatus(403)
            ->assertSee('Dot.Pulse is suspended for Acme Ltd')
            ->assertDontSee('name="team_id" value="'.$personal->id.'"', false);
    }
}
