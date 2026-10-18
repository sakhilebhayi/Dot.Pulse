<?php

namespace Tests\Feature\Pulse;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

/**
 * Markup contract of the responsive app shell (see README, "Responsive shell"):
 * below 860px the sidebar is an off-canvas drawer opened from a labelled Menu
 * button in the top bar. These are real authenticated GETs of the real routes;
 * the visual behaviour itself is checked in a browser, not here.
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
            'dashboard' => route('dashboard'),
            'post' => route('posts.show', $post),
            'community' => route('communities.show', $community),
            'user profile' => route('users.show', $post->author),
            'profile settings' => route('profile.show'),
            'team settings' => route('teams.show', $user->currentTeam),
        ];
    }

    public function test_every_shell_page_has_the_viewport_meta_menu_button_drawer_and_backdrop(): void
    {
        $user = $this->pulseUser();
        $this->actingAs($user);

        foreach ($this->shellUrls($user) as $label => $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1"', $html, "$label: viewport meta");

            // The labelled, wired-up menu button in the top bar.
            $this->assertMatchesRegularExpression(
                '/<header class="topbar"[^>]*>\s*<button type="button" id="shell-menu-button" class="shell-menu-btn" aria-expanded="false" aria-controls="shell-drawer">.*?<span>Menu<\/span>/s',
                $html,
                "$label: menu button"
            );

            // The drawer is the sidebar itself and is a named landmark.
            $this->assertMatchesRegularExpression('/<aside id="shell-drawer" class="sidebar" aria-label="[^"]+"/', $html, "$label: drawer");
            $this->assertStringContainsString('id="shell-close-button"', $html, "$label: close button");
            $this->assertStringContainsString('id="shell-backdrop"', $html, "$label: backdrop");
            $this->assertStringContainsString('class="content-wrap"', $html, "$label: content wrapper");
        }
    }

    public function test_the_legacy_jetstream_navigation_bar_is_no_longer_rendered_under_the_top_bar(): void
    {
        $user = $this->pulseUser();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Responsive Navigation Menu', false)
            ->assertDontSee('Primary Navigation Menu', false);
    }

    public function test_the_drawer_carries_sign_out_and_team_links(): void
    {
        $user = $this->pulseUser();

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
        $drawer = $this->between($html, '<aside id="shell-drawer"', '</aside>');

        $this->assertStringContainsString('action="'.route('logout').'"', $drawer);
        $this->assertStringContainsString('href="'.route('teams.show', $user->currentTeam).'"', $drawer);
        // One team only: nothing to switch to.
        $this->assertStringNotContainsString('Switch team', $drawer);
    }

    public function test_the_drawer_lists_every_team_for_switching_when_there_is_more_than_one(): void
    {
        $user = $this->pulseUser();
        $second = Team::factory()->create([
            'user_id' => $user->id,
            'personal_team' => false,
            'name' => 'Distinctive Second Workspace',
        ]);
        $user->teams()->attach($second, ['role' => 'editor']);

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
        $drawer = $this->between($html, '<aside id="shell-drawer"', '</aside>');

        $this->assertStringContainsString('Switch team', $drawer);
        $this->assertStringContainsString('Distinctive Second Workspace', $drawer);
        $this->assertStringContainsString('action="'.route('current-team.update').'"', $drawer);
        $this->assertSame(2, substr_count($drawer, 'name="team_id"'));
    }

    public function test_the_dashboard_uses_the_responsive_page_hooks(): void
    {
        $user = $this->pulseUser();

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('class="pulse-page"', $html);
        $this->assertStringContainsString('class="kpi-grid"', $html);
        $this->assertStringContainsString('class="feed-grid"', $html);
    }

    public function test_the_layout_defines_the_phone_breakpoint_the_table_wrapper_and_reduced_motion(): void
    {
        $user = $this->pulseUser();

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('@media (max-width:859.98px)', $html);
        $this->assertStringContainsString('.table-scroll { max-width:100%; overflow-x:auto;', $html);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $html);
    }

    public function test_the_notification_bell_is_in_the_top_bar_and_labelled(): void
    {
        $user = $this->pulseUser();

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
        $topbar = $this->between($html, '<header class="topbar"', '</header>');

        $this->assertStringContainsString('aria-label="Notifications"', $topbar);
        $this->assertStringContainsString('class="dot-card bell-panel"', $topbar);
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
