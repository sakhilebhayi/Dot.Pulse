<?php

namespace Tests\Feature;

use App\Livewire\Pulse\CreatePost;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

/**
 * Production gives every Dot app its own database, so InfoDot's handoff token is
 * not in ours. The app asks InfoDot to prove it, then matches or creates the user.
 */
class InfoDotRedeemTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    /** InfoDot's launch page, as the browser reports it. */
    private function fromInfodot(): array
    {
        $infodot = parse_url((string) config('ecosystem.infodot.redeem_url', ''), PHP_URL_HOST);

        if (is_string($infodot) && $infodot !== '') {
            return ['Origin' => 'https://'.$infodot];
        }

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return ['Origin' => 'https://'.(str_contains($host, '.') ? substr($host, strpos($host, '.') + 1) : $host)];
    }

    private const URL = 'https://infodot.test/api/ecosystem/redeem';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ecosystem.infodot' => [
            'redeem_url' => self::URL,
            'platform' => 'pulse',
            'secret' => 'service-secret-for-tests',
            'timeout' => 2,
            'provision' => true,
        ]]);
    }

    private function identity(array $user = [], array $extra = []): array
    {
        return array_merge([
            'user' => array_merge(['id' => 7, 'name' => 'Amara Dlamini', 'email' => 'Amara@Example.test', 'email_verified' => true], $user),
            'teams' => [['id' => 3, 'name' => 'Amara Team', 'personal' => true, 'role' => 'owner']],
            'current_team_id' => 3,
        ], $extra);
    }

    public function test_a_person_vouched_for_by_infodot_gets_an_account_and_is_signed_in(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 'one-time-token'], $this->fromInfodot())->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $this->assertSame('Amara Dlamini', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertCount(1, $user->ownedTeams);
        $this->assertTrue($user->ownedTeams->first()->personal_team);
    }

    public function test_a_provisioned_user_is_in_the_same_state_as_one_who_registered(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 'one-time-token'], $this->fromInfodot())->assertRedirect();

        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $team = $user->personalTeam();

        $this->assertNotNull($team, 'a personal team exists, as registration creates one');
        $this->assertSame("Amara's Team", $team->name);
        $this->assertSame($team->id, $user->currentTeam->id);

        $profile = PulseProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($profile, 'the Pulse profile exists, as the dashboard route would create it');
        $this->assertSame('customer', $profile->role);
        $this->assertSame(0, $profile->community_points);
        $this->assertFalse($user->isPlatformModerator());
        $this->assertFalse($user->canAccessModeration());
    }

    public function test_a_provisioned_user_can_open_the_dashboard_and_post_straight_away(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);
        $this->post(route('ecosystem.auth'), ['token' => 'one-time-token'], $this->fromInfodot())->assertRedirect();
        $user = User::where('email', 'amara@example.test')->firstOrFail();

        $this->get(route('dashboard'))->assertOk();

        Livewire::actingAs($user)->test(CreatePost::class)
            ->set('body', 'My very first post arriving through InfoDot sign-in.')
            ->call('publish')
            ->assertHasNoErrors();

        $post = PulsePost::where('user_id', $user->id)->firstOrFail();
        // Held for moderation (pending, or flagged when no AI key is set): never published unchecked.
        $this->assertNotSame('published', $post->status);
        $this->assertSame($user->personalTeam()->id, $post->team_id);
        $this->assertSame(1, PulseProfile::where('user_id', $user->id)->count(), 'still one profile after the dashboard visit');
    }

    public function test_a_provisioned_user_with_no_team_at_all_can_still_post(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);
        $this->post(route('ecosystem.auth'), ['token' => 'one-time-token'], $this->fromInfodot())->assertRedirect();
        $user = User::where('email', 'amara@example.test')->firstOrFail();

        // Teams are optional for posting (a user can leave or lose every team).
        $user->ownedTeams()->delete();
        $user->forceFill(['current_team_id' => null])->save();

        Livewire::actingAs($user->fresh())->test(CreatePost::class)
            ->set('body', 'Posting without any team to my name at all.')
            ->call('publish')
            ->assertHasNoErrors();

        $this->assertNull(PulsePost::where('user_id', $user->id)->firstOrFail()->team_id);
    }

    public function test_a_provisioned_user_gets_a_random_password_nobody_knows(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);
        $this->post(route('ecosystem.auth'), ['token' => 'one-time-token'], $this->fromInfodot());
        $first = User::where('email', 'amara@example.test')->firstOrFail();

        Http::swap(new Factory);
        Http::fake([self::URL => Http::response($this->identity(['email' => 'second@example.test']))]);
        auth()->logout();
        $this->post(route('ecosystem.auth'), ['token' => 'another-token'], $this->fromInfodot());
        $second = User::where('email', 'second@example.test')->firstOrFail();

        $this->assertNotSame($first->password, $second->password, 'each person gets a different random password');
        $this->assertFalse(Hash::check('password', $first->password));
        $this->assertFalse(Hash::check('', $first->password));
        $this->assertFalse(Hash::check('one-time-token', $first->password));
    }

    public function test_the_secret_and_platform_go_in_the_request_and_the_token_never_in_the_url(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 'one-time-token'], $this->fromInfodot());

        Http::assertSent(function (Request $request) {
            return $request->url() === self::URL
                && $request->hasHeader('Authorization', 'Bearer service-secret-for-tests')
                && $request['token'] === 'one-time-token'
                && $request['platform'] === 'pulse';
        });
    }

    public function test_an_existing_account_is_matched_by_email_without_a_duplicate(): void
    {
        $existing = User::factory()->withPersonalTeam()->create(['email' => 'amara@example.test', 'name' => 'Local Name']);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::where('email', 'amara@example.test')->count());
        $this->assertSame('Local Name', $existing->fresh()->name);
        $this->assertSame(1, Team::where('user_id', $existing->id)->count(), 'no second personal team');
        $this->assertSame(1, PulseProfile::where('user_id', $existing->id)->count());
    }

    public function test_an_existing_account_with_the_email_in_other_case_is_the_same_person(): void
    {
        $existing = User::factory()->withPersonalTeam()->create(['email' => 'AMARA@EXAMPLE.TEST']);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertRedirect();

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::count());
    }

    public function test_two_people_with_the_same_display_name_stay_two_distinct_users(): void
    {
        // Pulse has no handle or username column: people are addressed by id (/u/{id})
        // and identified by email. The only collisions are email, matched above, and
        // display names, which are not unique and must not merge accounts.
        $namesake = User::factory()->withPersonalTeam()->create(['name' => 'Amara Dlamini', 'email' => 'someone.else@example.test']);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertRedirect();

        $newcomer = User::where('email', 'amara@example.test')->firstOrFail();
        $this->assertNotSame($namesake->id, $newcomer->id);
        $this->assertSame(2, User::where('name', 'Amara Dlamini')->count());
        $this->assertAuthenticatedAs($newcomer);
        $this->assertNotSame($namesake->personalTeam()->id, $newcomer->personalTeam()->id);

        Http::swap(new Factory);
        Http::fake([self::URL => Http::response($this->identity())]);
        auth()->logout();
        $this->post(route('ecosystem.auth'), ['token' => 'again'], $this->fromInfodot())->assertRedirect();
        $this->assertSame(2, User::count(), 'the same person signing in again is not provisioned twice');
        $this->assertSame(1, PulseProfile::where('user_id', $newcomer->id)->count());
    }

    public function test_an_unverified_local_account_is_adopted_and_its_password_replaced(): void
    {
        $squatter = User::factory()->withPersonalTeam()->create([
            'email' => 'amara@example.test',
            'email_verified_at' => null,
            'password' => Hash::make('attacker-knows-this'),
        ]);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot());

        $this->assertAuthenticatedAs($squatter);
        $this->assertFalse(Hash::check('attacker-knows-this', $squatter->fresh()->password));
        $this->assertNotNull($squatter->fresh()->email_verified_at);
    }

    public function test_a_token_infodot_refuses_signs_nobody_in(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'invalid_request'], 403)]);

        $this->post(route('ecosystem.auth'), ['token' => 'bad'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(0, User::count());
        $this->assertSame(0, PulseProfile::count());
    }

    public function test_a_wrong_service_secret_is_a_forbidden_not_a_sign_in(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'unauthenticated'], 401)]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_success_response_without_an_identity_signs_nobody_in(): void
    {
        Http::fake([self::URL => Http::response(['ok' => true])]);
        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        Http::swap(new Factory);
        Http::fake([self::URL => Http::response('not json at all', 200)]);
        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_an_identity_infodot_does_not_vouch_for_is_refused(): void
    {
        Http::fake([self::URL => Http::response($this->identity(['email_verified' => false]))]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_missing_or_loosely_typed_verified_flag_is_never_accepted(): void
    {
        foreach ([null, 'true', 1, '1', 'yes', [true]] as $value) {
            Http::swap(new Factory);
            $identity = $this->identity();
            $identity['user']['email_verified'] = $value;
            Http::fake([self::URL => Http::response($identity)]);

            $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        }

        Http::swap(new Factory);
        $identity = $this->identity();
        unset($identity['user']['email_verified']);
        Http::fake([self::URL => Http::response($identity)]);
        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_an_unverified_identity_does_not_even_touch_an_existing_account(): void
    {
        $existing = User::factory()->create(['email' => 'amara@example.test', 'email_verified_at' => null, 'password' => Hash::make('keep-me')]);
        Http::fake([self::URL => Http::response($this->identity(['email_verified' => false]))]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        $this->assertGuest();
        $this->assertTrue(Hash::check('keep-me', $existing->fresh()->password));
        $this->assertNull($existing->fresh()->email_verified_at);
        $this->assertSame(0, PulseProfile::count());
    }

    public function test_when_infodot_is_down_the_person_is_told_to_retry_not_that_they_are_forbidden(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login')->assertSessionHas('status', fn (string $m) => str_contains($m, 'unavailable'));

        Http::swap(new Factory);
        Http::fake([self::URL => Http::response('slow down', 429)]);
        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login')->assertSessionHas('status', fn (string $m) => str_contains($m, 'unavailable'));

        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login')->assertSessionHas('status', fn (string $m) => str_contains($m, 'unavailable'));

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_without_a_secret_the_old_local_only_behaviour_remains_and_infodot_is_never_called(): void
    {
        config(['ecosystem.infodot.secret' => null]);
        Http::fake();

        $this->post(route('ecosystem.auth'), ['token' => 'unknown'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_a_token_in_our_own_database_still_works_and_is_spent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        // Inserted directly so the test does not depend on how createToken() fills columns.
        $plain = bin2hex(random_bytes(20));
        $record = PersonalAccessToken::forceCreate([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'handoff',
            'token' => hash('sha256', $plain),
            'abilities' => ['ecosystem:read'],
        ]);
        $token = $record->id.'|'.$plain;
        Http::fake();

        $this->post(route('ecosystem.auth'), ['token' => $token], $this->fromInfodot())->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        Http::assertNothingSent();

        auth()->logout();
        $this->post(route('ecosystem.auth'), ['token' => $token], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
    }

    public function test_an_expired_or_wrong_ability_local_token_is_refused(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        config(['ecosystem.infodot.secret' => null]);

        foreach ([[['ecosystem:read'], now()->subMinute()], [['read'], null]] as [$abilities, $expires]) {
            $plain = bin2hex(random_bytes(20));
            $record = PersonalAccessToken::forceCreate([
                'tokenable_type' => User::class,
                'tokenable_id' => $user->id,
                'name' => 'handoff',
                'token' => hash('sha256', $plain),
                'abilities' => $abilities,
                'expires_at' => $expires,
            ]);

            $this->post(route('ecosystem.auth'), ['token' => $record->id.'|'.$plain], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        }

        $this->assertGuest();
    }

    public function test_provisioning_can_be_switched_off(): void
    {
        config(['ecosystem.infodot.provision' => false]);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        $this->assertSame(0, User::count());
        $this->assertSame(0, PulseProfile::count());
    }

    public function test_a_missing_or_non_string_token_is_forbidden_without_calling_infodot(): void
    {
        Http::fake();

        $this->post(route('ecosystem.auth'), [], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->post(route('ecosystem.auth'), ['token' => ''], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->post(route('ecosystem.auth'), ['token' => ['x']], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        $this->post(route('ecosystem.auth'), ['token[a][b]' => 'x'], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_a_safe_deep_link_is_followed_after_sign_in(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->post(route('ecosystem.auth'), ['token' => 't', 'redirect' => '/moderation?tab=queue'], $this->fromInfodot())
            ->assertRedirect(url('/moderation?tab=queue'));

        $this->assertAuthenticated();
    }

    public function test_open_redirect_attempts_fall_back_to_the_dashboard(): void
    {
        $attempts = [
            'https://evil.test/phish',
            '//evil.test/phish',
            '///evil.test',
            '/\\evil.test',
            '\\\\evil.test',
            'evil.test/phish',
            'javascript:alert(1)',
            '/../../etc/passwd',
            '/a/../b',
            '/%2e%2e/secret',
            '/%2F%2Fevil.test',
            "/ok\r\nLocation: https://evil.test",
            ['https://evil.test'],
            '',
        ];

        foreach ($attempts as $attempt) {
            Http::swap(new Factory);
            Http::fake([self::URL => Http::response($this->identity())]);
            auth()->logout();
            Cache::flush(); // the throttle has its own test; this loop makes more than 10 requests

            $this->post(route('ecosystem.auth'), ['token' => 't', 'redirect' => $attempt], $this->fromInfodot())
                ->assertRedirect(route('dashboard'));
        }
    }

    public function test_the_session_id_changes_at_sign_in(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);
        $this->startSession();
        $before = session()->getId();

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertRedirect();

        $this->assertNotSame($before, session()->getId());
    }

    public function test_the_endpoint_is_throttled(): void
    {
        Http::fake();

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('ecosystem.auth'), ['token' => 'guess-'.$i], $this->fromInfodot())->assertStatus(303)->assertRedirect('/login');
        }

        $this->post(route('ecosystem.auth'), ['token' => 'guess-11'], $this->fromInfodot())->assertStatus(429);
    }

    public function test_provisioning_never_makes_anyone_a_platform_moderator_or_admin(): void
    {
        // Even if the payload tried to say so, platform roles are local-only.
        $claims = ['role' => 'admin', 'pulse_role' => 'moderator', 'is_admin' => true, 'is_platform_operator' => true, 'is_verified' => true];
        Http::fake([self::URL => Http::response($this->identity($claims, $claims + [
            'teams' => [['id' => 3, 'uuid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Acme', 'personal' => false, 'role' => 'owner']],
        ]))]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertRedirect();

        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $profile = PulseProfile::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('customer', $profile->role);
        $this->assertFalse((bool) $profile->is_verified);
        $this->assertFalse($user->isPlatformModerator());
        $this->assertFalse($user->canAccessModeration());
        $this->get(route('moderation'))->assertForbidden();
    }

    public function test_an_existing_user_without_a_pulse_profile_never_gains_a_platform_role(): void
    {
        $plain = User::factory()->withPersonalTeam()->create(['email' => 'amara@example.test']);
        Http::fake([self::URL => Http::response($this->identity(['role' => 'admin', 'pulse_role' => 'admin'], ['role' => 'admin']))]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertRedirect();

        $this->assertAuthenticatedAs($plain);
        $this->assertSame('customer', $plain->pulseProfile()->firstOrFail()->role);
        $this->assertFalse($plain->fresh()->isPlatformModerator());
    }

    public function test_existing_moderators_and_admins_keep_their_role_and_profile_untouched(): void
    {
        $moderator = $this->platformModerator();
        $moderator->forceFill(['email' => 'amara@example.test'])->save();
        $moderator->pulseProfile->update(['community_points' => 120, 'headline' => 'Keeps the peace']);

        $admin = $this->pulseUser('admin');
        $admin->forceFill(['email' => 'boss@example.test'])->save();

        Http::fake([self::URL => Http::response($this->identity(['role' => 'customer', 'pulse_role' => 'customer']))]);
        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->fromInfodot())->assertRedirect();
        $this->assertAuthenticatedAs($moderator);

        $profile = $moderator->fresh()->pulseProfile;
        $this->assertSame('moderator', $profile->role, 'sign-in through InfoDot does not demote a moderator');
        $this->assertSame(120, $profile->community_points);
        $this->assertSame('Keeps the peace', $profile->headline);
        $this->assertTrue($moderator->fresh()->isPlatformModerator());
        $this->assertSame(1, PulseProfile::where('user_id', $moderator->id)->count());

        Http::swap(new Factory);
        Http::fake([self::URL => Http::response($this->identity(['email' => 'boss@example.test']))]);
        auth()->logout();
        $this->post(route('ecosystem.auth'), ['token' => 't2'], $this->fromInfodot())->assertRedirect();
        $this->assertAuthenticatedAs($admin);
        $this->assertSame('admin', $admin->fresh()->pulseProfile->role);
    }
}
