<?php

namespace Tests\Feature\Sso;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Exceptions\ClaimRequired;
use App\Mail\SsoClaimCodeMail;
use App\Models\PulseEntity;
use App\Models\PulseProfile;
use App\Models\Team;
use App\Models\User;
use App\Services\Pulse\Entities\VerificationService;
use App\Sso\PulseProvisioner;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Business\BusinessTestCase;

/** R-54 phase 2 pilot: Dot.Pulse behind DOTSSO_ENABLED. Off, nothing changes; on, every entry goes to InfoDot. */
class CentralSignInRolloutTest extends BusinessTestCase
{
    private FakeIdp $idp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idp = (new FakeIdp)->install();
        config([
            'dotsso.provisioner' => PulseProvisioner::class,
            'dotsso.enabled' => false,
        ]);
        Mail::fake();
    }

    private function on(): void
    {
        config(['dotsso.enabled' => true]);
    }

    private function signInVia(array $claims = [], array $session = [])
    {
        $response = $this->withSession($session)->get('/sso/login')->assertRedirect();
        $authorize = FakeIdp::authorizeParams($response->headers->get('Location'));
        // The fake provider's redirect URI is the one this test app uses.
        $code = $this->idp->approve($authorize, $claims);

        return $this->get('/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]));
    }

    public function test_the_users_table_has_a_nullable_unique_infodot_user_uuid(): void
    {
        $a = User::factory()->create();
        $this->assertNull($a->infodot_user_uuid);
        User::factory()->create(); // two NULLs are allowed

        $a->forceFill(['infodot_user_uuid' => 'x'])->save();
        $this->expectException(QueryException::class);
        User::factory()->create()->forceFill(['infodot_user_uuid' => 'x'])->save();
    }

    public function test_with_the_flag_off_nothing_changes(): void
    {
        $this->get('/login')->assertOk()->assertSee('Use a Pulse password instead');
        $this->get('/sso/login')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('/sso/login', false);
        $this->get('/enter')->assertRedirect(route('login'));
    }

    public function test_with_the_flag_on_the_local_pages_go_to_infodot_sign_in(): void
    {
        $this->on();

        $this->get('/login')->assertRedirect(route('dotsso.login'));
        $this->get('/register')->assertRedirect(route('dotsso.login', ['screen' => 'register']));
        $this->get('/forgot-password')->assertRedirect(route('dotsso.login'));
        $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'])->assertRedirect(route('dotsso.login'));
    }

    public function test_the_signed_out_pages_link_straight_to_the_central_sign_in(): void
    {
        $this->on();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(route('dotsso.login'), $html);
        $this->assertStringContainsString(route('dotsso.login', ['screen' => 'register']), $html);
        $this->assertStringContainsString('Sign in with InfoDot', $html);
    }

    public function test_the_break_glass_switch_brings_the_local_login_back(): void
    {
        $this->on();
        config(['dotsso.allow_local_login' => true]);

        $this->get('/login')->assertOk()->assertSee('Use a Pulse password instead');
    }

    public function test_a_signed_out_deep_link_survives_the_round_trip(): void
    {
        $this->on();
        $this->get('/community')->assertRedirect('/login');
        $this->get('/login')->assertRedirect(route('dotsso.login'));

        $this->signInVia()->assertRedirect('/community');
        $this->assertAuthenticated();
    }

    public function test_a_first_sign_in_creates_the_account_with_the_infodot_id_and_a_personal_team(): void
    {
        $this->on();

        $this->signInVia(['sub' => 'infodot-uuid-9', 'email' => 'new@example.test', 'name' => 'Nia New'])->assertRedirect('/dashboard');

        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('infodot-uuid-9', $user->infodot_user_uuid);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Team::where('user_id', $user->id)->where('personal_team', true)->exists());
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_unverified_local_user_claims_with_a_code_and_keeps_data_and_password(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'ada@example.test', 'email_verified_at' => null, 'password' => Hash::make('old-password-1')]);
        $team = $user->ownedTeams()->first();
        $hash = $user->password;

        $this->signInVia(['sub' => 'infodot-uuid-5'])->assertRedirect(route('dotsso.claim'));
        $this->assertGuest();

        $sent = null;
        Mail::assertSent(SsoClaimCodeMail::class, function ($m) use (&$sent) {
            $sent = $m->code;

            return $m->hasTo('ada@example.test');
        });

        $this->get('/sso/claim')->assertOk()->assertSee('Confirm it is you')->assertSee('a**@example.test', false)->assertDontSee($sent);
        $this->post('/sso/claim', ['code' => $sent])->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertSame('infodot-uuid-5', $user->infodot_user_uuid);
        $this->assertSame($hash, $user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($team->fresh()->is($user->ownedTeams()->first()), 'team membership untouched');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_claim_page_is_one_form_with_labelled_code_field_and_real_buttons(): void
    {
        $this->on();
        User::factory()->create(['email' => 'ada@example.test', 'email_verified_at' => null]);
        $this->signInVia()->assertRedirect(route('dotsso.claim'));

        $html = $this->get('/sso/claim')->assertOk()->getContent();

        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertMatchesRegularExpression('/<label[^>]+for="code"/', $html);
        $this->assertSame(1, substr_count($html, '<h1'));

        $wrong = $this->followingRedirects()->post('/sso/claim', ['code' => '000000'])->getContent();
        $this->assertMatchesRegularExpression('/tr(y|ies) left/', $wrong);
    }

    public function test_after_an_infodot_sign_out_a_real_auth_sanctum_page_ends_the_session_instead_of_a_500(): void
    {
        $this->on();
        $this->signInVia(['sub' => 'infodot-uuid-3', 'email' => 'out@example.test'])->assertRedirect('/dashboard');
        DB::table('pulse_profiles')->where('user_id', User::where('email', 'out@example.test')->value('id'))->update(['age_confirmed_at' => now(), 'onboarded_at' => now()]);
        $this->get('/floor')->assertOk();

        $this->travel(1)->seconds();
        DB::table('dotsso_revocations')->insert(['sub' => 'infodot-uuid-3', 'revoked_at_ms' => (int) floor(microtime(true) * 1000), 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/floor')->assertRedirect('/');
        $this->assertGuest('web');
    }

    public function test_sanctum_api_access_is_untouched_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/user')->assertOk()->assertJsonPath('email', $user->email);
    }

    public function test_a_real_sanctum_bearer_token_works_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->getJson('/api/user')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/user')->assertOk();
    }

    public function test_the_health_and_fallback_receiver_routes_are_unaffected(): void
    {
        $this->on();

        $this->get('/up')->assertOk();
        $this->get('/auth/ecosystem')->assertRedirect('/login');
    }

    public function test_a_first_sign_in_creates_the_pulse_profile_with_the_customer_role(): void
    {
        $this->on();

        $this->signInVia(['sub' => 'infodot-uuid-11', 'email' => 'prof@example.test', 'name' => 'Pru Profile']);

        $user = User::where('email', 'prof@example.test')->firstOrFail();
        $profile = PulseProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($profile, 'the PulseProfile is created at first sign-in');
        $this->assertSame('customer', $profile->role);
    }

    public function test_a_moderator_keeps_the_role_and_a_token_cannot_grant_one(): void
    {
        $this->on();
        $mod = User::factory()->withPersonalTeam()->create(['email' => 'mod@example.test', 'email_verified_at' => now()]);
        PulseProfile::create(['user_id' => $mod->id, 'role' => 'moderator', 'community_points' => 7]);

        $this->signInVia(['sub' => 'infodot-uuid-12', 'email' => 'mod@example.test', 'role' => 'admin', 'roles' => ['admin']]);

        $this->assertSame('infodot-uuid-12', $mod->fresh()->infodot_user_uuid);
        $profile = PulseProfile::where('user_id', $mod->id)->first();
        $this->assertSame('moderator', $profile->role);
        $this->assertSame(7, (int) $profile->community_points);
    }

    public function test_every_sign_in_refreshes_the_verification_of_the_signed_in_persons_pages(): void
    {
        $this->on();
        $spy = new class extends VerificationService
        {
            public array $users = [];

            public function refreshForUser(User $user, ?array $identity = null): int
            {
                $this->users[] = $user->id;

                return 0;
            }
        };
        $this->app->instance(VerificationService::class, $spy);

        $this->signInVia(['sub' => 'infodot-uuid-13', 'email' => 'ver@example.test'])->assertRedirect('/dashboard');

        $this->assertSame([User::where('email', 'ver@example.test')->value('id')], $spy->users);
    }

    public function test_a_failing_verification_refresh_never_blocks_the_sign_in(): void
    {
        $this->on();
        $this->app->bind(VerificationService::class, fn () => new class extends VerificationService
        {
            public function refreshForUser(User $user, ?array $identity = null): int
            {
                throw new \RuntimeException('InfoDot unreachable');
            }
        });

        $this->signInVia(['sub' => 'infodot-uuid-14', 'email' => 'down@example.test'])->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_the_landing_door_goes_to_the_platform_aware_sign_in_not_the_bare_infodot_origin(): void
    {
        $this->on();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('dotsso.login').'"', $html);
        $this->assertStringNotContainsString('class="pk-btn pk-btn--signal" href="'.pulse_infodot_url().'"', $html);
    }

    public function test_the_knock_link_on_a_business_page_comes_back_to_that_page_after_sign_in(): void
    {
        $this->on();
        $entity = $this->publicEntity();
        $page = '/b/'.$entity->slug;

        $html = $this->get($page)->assertOk()->getContent();
        $this->assertStringContainsString('Sign in with InfoDot to knock', $html);
        $this->assertStringContainsString(e(route('pulse.enter', ['to' => $page])), $html);

        $this->get('/enter?to='.urlencode($page))->assertRedirect(route('dotsso.login'));
        $this->signInVia(['sub' => 'infodot-uuid-15', 'email' => 'knock@example.test'])->assertRedirect($page);
    }

    public function test_the_enter_route_refuses_a_foreign_or_looping_return_address(): void
    {
        $this->on();

        foreach (['https://evil.test/x', '//evil.test', '/login', '/sso/callback', '/dev/login/1', '/a\\b'] as $to) {
            $this->get('/enter?to='.urlencode($to))->assertRedirect(route('dotsso.login'));
            $this->assertNull(session('url.intended'), $to);
        }
    }

    public function test_the_receiver_no_longer_replaces_an_unverified_accounts_password_and_makes_no_profile(): void
    {
        $user = User::factory()->create(['email' => 'amara@example.test', 'email_verified_at' => null, 'password' => Hash::make('keep-me-1')]);
        $hash = $user->password;

        try {
            app(ProvisionUserFromInfoDot::class)(['user' => ['uuid' => 'u-1', 'email' => 'amara@example.test', 'email_verified' => true, 'name' => 'Amara']]);
            $this->fail('the claim flow was expected');
        } catch (ClaimRequired) {
            $this->assertSame($hash, $user->fresh()->password);
            $this->assertNull($user->fresh()->email_verified_at);
        }
    }

    public function test_a_real_auth_sanctum_route_keeps_the_session_guard_default(): void
    {
        $this->assertSame('web', config('dotsso.guard'));
    }

    private function publicEntity(): PulseEntity
    {
        return $this->page();
    }
}
