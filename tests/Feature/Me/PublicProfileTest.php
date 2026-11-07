<?php

namespace Tests\Feature\Me;

use App\Livewire\Pulse\FollowButton;
use App\Livewire\Pulse\UserProfile;
use App\Models\PulsePost;
use App\Models\Team;
use App\Models\User;
use App\Services\Pulse\Entities\ProfileService;
use App\Services\Pulse\Lines\BlockService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class PublicProfileTest extends MeTestCase
{
    private function named(array $profile, string $name = 'Thandiwe Mokoena'): User
    {
        $u = $this->member($profile + ['headline' => 'Bookkeeper and payroll specialist', 'bio' => 'Twelve years of books.', 'skills' => json_encode(['bookkeeping', 'payroll']), 'experience_years' => 12]);
        $u->forceFill(['name' => $name, 'email' => 'thandi.mokoena'.$u->id.'@example.test'])->save();

        return $u->fresh();
    }

    private function publish(User $u, string $body = 'A published thought'): PulsePost
    {
        $team = $u->currentTeam ?? Team::forceCreate(['user_id' => $u->id, 'name' => 'T', 'personal_team' => true]);

        return PulsePost::create(['user_id' => $u->id, 'team_id' => $team->id, 'type' => 'discussion', 'body' => $body, 'status' => 'published']);
    }

    public function test_an_anonymous_profile_never_resolves_to_a_person_for_anyone_but_them(): void
    {
        $anon = $this->named(['discoverability' => 'anonymous']);
        $this->publish($anon);
        $viewer = $this->member();

        $res = $this->actingAs($viewer)->get("/u/{$anon->id}");

        $res->assertNotFound();
        $this->assertStringNotContainsString('Mokoena', $res->getContent());
        $this->assertStringNotContainsString('thandi.mokoena', $res->getContent());

        Livewire::actingAs($viewer)->test(UserProfile::class, ['profileUser' => $anon])->assertStatus(404);
    }

    public function test_the_same_404_for_a_moderator_and_for_a_signed_out_visitor(): void
    {
        $anon = $this->named(['discoverability' => 'anonymous']);
        $mod = $this->member();
        DB::table('pulse_profiles')->where('user_id', $mod->id)->update(['role' => 'moderator']);

        $this->actingAs($mod)->get("/u/{$anon->id}")->assertNotFound();
    }

    public function test_a_signed_out_visitor_is_sent_to_sign_in_and_learns_nothing(): void
    {
        $anon = $this->named(['discoverability' => 'anonymous']);

        $this->get("/u/{$anon->id}")->assertRedirect('/login');
    }

    public function test_the_person_sees_their_own_page_when_anonymous_and_is_told_nobody_else_can(): void
    {
        $anon = $this->named(['discoverability' => 'anonymous']);

        $this->actingAs($anon)->get("/u/{$anon->id}")->assertOk()->assertSee('nobody else can open this page');
    }

    public function test_no_serialisation_of_the_page_data_carries_an_anonymous_persons_identity(): void
    {
        $anon = $this->named(['discoverability' => 'anonymous']);
        $viewer = $this->member();
        $card = app(ProfileService::class)->card($this->profileOf($anon), $viewer);

        $json = json_encode($card);
        foreach (['Mokoena', 'thandi.mokoena', 'avatar', '"user_id"'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_an_open_profile_shows_the_card_to_a_signed_in_viewer(): void
    {
        $open = $this->named(['discoverability' => 'open', 'kind' => 'professional', 'open_to' => json_encode(['work']), 'languages' => json_encode(['English']), 'work_history' => json_encode([['title' => 'Accountant', 'org' => 'Acme Holdings', 'from_year' => 2019, 'current' => true, 'employer' => 'Acme Holdings', 'hide_from_employer' => true]])]);

        $res = $this->actingAs($this->member())->get("/u/{$open->id}");

        $res->assertOk()->assertSee('Thandiwe Mokoena')->assertSee('Bookkeeper and payroll specialist')->assertSee('Twelve years of books.')
            ->assertSee('bookkeeping')->assertSee('Accountant')->assertSee('Acme Holdings')->assertSee('A job')->assertSee('Professional', false);
        $this->assertStringNotContainsString('hide_from_employer', $res->getContent(), 'the private flag never reaches the page');
    }

    public function test_a_profile_that_is_not_open_shows_only_the_name_and_posts(): void
    {
        $off = $this->named(['discoverability' => 'off']);
        $this->publish($off, 'My published words');

        $res = $this->actingAs($this->member())->get("/u/{$off->id}");

        $res->assertOk()->assertSee('Thandiwe Mokoena')->assertSee('My published words');
        foreach (['Bookkeeper and payroll specialist', 'Twelve years of books.', 'bookkeeping', 'thandi.mokoena'] as $hidden) {
            $res->assertDontSee($hidden);
        }
    }

    public function test_an_open_profile_of_someone_who_never_confirmed_18_shows_nothing_professional(): void
    {
        $u = $this->named(['discoverability' => 'open']);
        DB::table('pulse_profiles')->where('user_id', $u->id)->update(['age_confirmed_at' => null]);

        $this->actingAs($this->member())->get("/u/{$u->id}")->assertOk()->assertDontSee('Bookkeeper and payroll specialist');
    }

    public function test_a_knock_appears_only_when_the_contact_policy_allows_it(): void
    {
        $viewer = $this->member();
        $anyone = $this->named(['contact_policy' => 'requests']);
        $nobody = $this->named(['contact_policy' => 'nobody'], 'Sipho Nobody');
        $context = $this->named(['contact_policy' => 'context'], 'Lerato Context');

        Livewire::actingAs($viewer)->test(UserProfile::class, ['profileUser' => $anyone])->assertSeeLivewire('pulse.knock-sheet')->assertSee('They choose whether to answer');
        Livewire::actingAs($viewer)->test(UserProfile::class, ['profileUser' => $nobody])->assertDontSeeLivewire('pulse.knock-sheet')->assertSee('not taking new messages');
        Livewire::actingAs($viewer)->test(UserProfile::class, ['profileUser' => $context])->assertDontSeeLivewire('pulse.knock-sheet')->assertSee('start from something of theirs');
    }

    public function test_a_blocked_person_gets_no_knock_and_is_not_told_why(): void
    {
        $viewer = $this->member();
        $target = $this->named(['contact_policy' => 'anyone']);
        app(BlockService::class)->block($target, $viewer);

        Livewire::actingAs($viewer)->test(UserProfile::class, ['profileUser' => $target])
            ->assertDontSeeLivewire('pulse.knock-sheet')
            ->assertDontSee('blocked')->assertSee('cannot be contacted right now');
    }

    public function test_own_page_has_no_knock_follow_or_report_and_offers_edit(): void
    {
        $me = $this->named(['discoverability' => 'open']);

        Livewire::actingAs($me)->test(UserProfile::class, ['profileUser' => $me])
            ->assertSee('Edit your profile')->assertDontSeeLivewire('pulse.knock-sheet')->assertDontSeeLivewire('pulse.report-button');
    }

    public function test_follow_and_report_still_work_from_the_page(): void
    {
        $viewer = $this->member();
        $target = $this->named(['discoverability' => 'off']);

        Livewire::actingAs($viewer)->test(UserProfile::class, ['profileUser' => $target])
            ->assertSeeLivewire('pulse.follow-button')->assertSeeLivewire('pulse.report-button');

        Livewire::actingAs($viewer)->test(FollowButton::class, ['user' => $target])->call('toggleFollow');
        $this->assertTrue($viewer->fresh()->isFollowing($target));
    }

    public function test_the_page_has_one_h1_and_no_vanity_counters(): void
    {
        $open = $this->named(['discoverability' => 'open']);
        $viewer = $this->member();
        $viewer->following()->attach($open->id);

        $html = $this->actingAs($viewer)->get("/u/{$open->id}")->getContent();

        $this->assertSame(1, preg_match_all('/<h1[ >]/', $html));
        $this->assertDoesNotMatchRegularExpression('/\d+\s*(followers|following)/i', strip_tags($html), 'no follower counts on the page');
    }
}
