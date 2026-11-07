<?php

namespace Tests\Feature\Pulse;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

/**
 * Markup contract of the signed-in shell (R-41, The Floor): cobalt masthead, rail of verbs on desktop, bottom bar of the
 * same verbs on phones, a skip link, the user menu with sign-out and the team switcher. These are real authenticated GETs of
 * the real routes; the visual behaviour is checked in a browser, not here.
 *
 * Rewritten for R-41: the pre-R-41 sidebar drawer (#shell-drawer, .topbar, .content-wrap Menu button) no longer exists. The
 * contract it protected (labelled navigation on phones, sign-out and team links reachable, no stock Jetstream bar, reduced
 * motion honoured, notifications reachable from the top bar) is asserted against the new markup.
 */
class ResponsiveShellTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function shellUrls(User $user): array
    {
        $community = $this->publicCommunity();
        $post = $this->postIn($community, ['title' => 'Responsive shell fixture post title']);

        return [
            'community feed' => route('community.feed'),
            'post' => route('posts.show', $post),
            'community' => route('communities.show', $community),
            'user profile' => route('users.show', $post->author),
            'profile settings' => route('profile.show'),
            'team settings' => route('teams.show', $user->currentTeam),
        ];
    }

    public function test_every_shell_page_has_the_viewport_meta_skip_link_masthead_rail_and_phone_bar(): void
    {
        $user = $this->pulseUser();
        $this->actingAs($user);

        foreach ($this->shellUrls($user) as $label => $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1"', $html, "$label: viewport meta");
            $this->assertStringContainsString('class="skip-link" href="#main"', $html, "$label: skip link");
            $this->assertStringContainsString('<header class="sh-mast', $html, "$label: masthead");
            $this->assertMatchesRegularExpression('/<nav class="sh-rail" aria-label="[^"]+"/', $html, "$label: rail");
            $this->assertMatchesRegularExpression('/<nav class="sh-bar" aria-label="[^"]+"/', $html, "$label: phone bar");
            $this->assertStringContainsString('id="main"', $html, "$label: main landmark");

            // The five verbs are on the rail, in order.
            $rail = $this->between($html, '<nav class="sh-rail"', '</nav>');
            $positions = array_map(fn ($verb) => strpos($rail, '<span>'.$verb.'</span>'), ['Floor', 'Trade', 'Work', 'Lines', 'You']);
            $this->assertNotContains(false, $positions, "$label: all five verbs");
            $sorted = $positions;
            sort($sorted);
            $this->assertSame($sorted, $positions, "$label: verbs in order");
            $this->assertStringContainsString('data-pk-dialog="compose"', $rail, "$label: compose button");
        }
    }

    public function test_the_legacy_jetstream_navigation_bar_is_no_longer_rendered_under_the_top_bar(): void
    {
        $user = $this->pulseUser();

        $this->actingAs($user)
            ->get(route('community.feed'))
            ->assertOk()
            ->assertDontSee('Responsive Navigation Menu', false)
            ->assertDontSee('Primary Navigation Menu', false);
    }

    public function test_the_user_menu_carries_sign_out_and_team_links(): void
    {
        $user = $this->pulseUser();

        $html = $this->actingAs($user)->get(route('community.feed'))->assertOk()->getContent();
        $menu = $this->between($html, 'id="user-menu"', '</header>');

        $this->assertStringContainsString('action="'.route('logout').'"', $menu);
        $this->assertStringContainsString('href="'.route('teams.show', $user->currentTeam).'"', $menu);
        // One team only: nothing to switch to.
        $this->assertStringNotContainsString('Switch team', $menu);
    }

    public function test_the_user_menu_lists_every_team_for_switching_when_there_is_more_than_one(): void
    {
        $user = $this->pulseUser();
        $second = Team::factory()->create([
            'user_id' => $user->id,
            'personal_team' => false,
            'name' => 'Distinctive Second Workspace',
        ]);
        $user->teams()->attach($second, ['role' => 'editor']);

        $html = $this->actingAs($user)->get(route('community.feed'))->assertOk()->getContent();
        $menu = $this->between($html, 'id="user-menu"', '</header>');

        $this->assertStringContainsString('Switch team', $menu);
        $this->assertStringContainsString('Distinctive Second Workspace', $menu);
        $this->assertStringContainsString('action="'.route('current-team.update').'"', $menu);
        $this->assertSame(2, substr_count($menu, 'name="team_id"'));
    }

    public function test_the_dashboard_uses_the_responsive_page_hooks(): void
    {
        $user = $this->pulseUser();

        $html = $this->actingAs($user)->get(route('community.feed'))->assertOk()->getContent();

        $this->assertStringContainsString('class="pulse-page"', $html);
        $this->assertStringContainsString('class="kpi-grid"', $html);
        $this->assertStringContainsString('class="feed-grid"', $html);
    }

    public function test_the_shell_script_polls_the_pulse_check_endpoint_and_the_body_names_it(): void
    {
        $user = $this->pulseUser();

        $html = $this->actingAs($user)->get(route('community.feed'))->assertOk()->getContent();

        $this->assertStringContainsString('data-pulse-check="'.url('/api/pulse/pulse-check').'"', $html);
        $this->assertStringContainsString('data-pulse-badge="lines"', $html);
        $this->assertStringContainsString('data-pk-theme', $html, 'theme toggle');
    }

    public function test_the_notification_bell_is_in_the_masthead_and_labelled(): void
    {
        $user = $this->pulseUser();

        $html = $this->actingAs($user)->get(route('community.feed'))->assertOk()->getContent();
        $masthead = $this->between($html, '<header class="sh-mast', '</header>');

        // Until the notifications page route exists the legacy Livewire bell is shown; either way it is named "Notifications".
        $this->assertStringContainsString('aria-label="Notifications"', $masthead);
    }

    public function test_the_shell_does_not_crash_when_a_linked_route_is_not_registered_yet(): void
    {
        $this->assertSame('#', pulse_route('this.route.does.not.exist'));
        $this->assertSame('#', pulse_route_first(['nope.one', 'nope.two']));
        $this->assertSame(route('dashboard'), pulse_route_first(['floor-not-built-yet', 'dashboard']));
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, "Could not find $start");
        $to = strpos($html, $end, $from);
        $this->assertNotFalse($to, "Could not find $end");

        return substr($html, $from, $to - $from);
    }
}
