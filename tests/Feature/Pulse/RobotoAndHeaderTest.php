<?php

namespace Tests\Feature\Pulse;

use App\Livewire\Pulse\HeaderSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\Support\DiscoveryFixtures;
use Tests\TestCase;

/**
 * R-52: Roboto is the face, the header is the shared dot-ui header, and the search is in it on every page of the app, scoped to what
 * the viewer may see.
 */
class RobotoAndHeaderTest extends TestCase
{
    use BuildsPulseFixtures;
    use DiscoveryFixtures;
    use RefreshDatabase;

    public function test_roboto_is_declared_and_served_from_this_site(): void
    {
        $html = $this->actingAs($this->pulseUser())->get('/floor')->assertOk()->getContent();

        $this->assertStringContainsString('class="dot-roboto"', $html);
        $this->assertStringContainsString(asset('dot-ui/css/dot-roboto.css'), $html);
        $this->assertStringContainsString(asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2'), $html);
        $this->assertStringContainsString("font-family: 'Roboto'", (string) file_get_contents(public_path('dot-ui/css/dot-roboto.css')));
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2'));
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/roboto-latin-ext-wght-normal.woff2'));
    }

    public function test_no_other_text_face_or_external_font_url_is_left(): void
    {
        $css = '';
        foreach (glob(resource_path('css/pulse/*.css')) as $file) {
            $css .= file_get_contents($file);
        }

        $this->assertStringNotContainsString('Archivo', $css);
        $this->assertStringNotContainsString('font-stretch', $css, 'Roboto has no width axis');
        $this->assertDoesNotMatchRegularExpression('#url\(\s*[\'"]?(https?:)?//#', $css);
        $this->assertSame([], glob(public_path('fonts/archivo*')));
        $this->assertSame([], glob(public_path('dot-ui/**/*archivo*')));
        $this->assertStringContainsString('--f-ui: var(--dot-font-sans', $css);
        $this->assertStringContainsString('font-family: "JetBrains Mono"', $css, 'the monospace readouts stay');

        $guest = $this->get('/login')->assertOk()->getContent();
        $this->assertStringNotContainsString('fonts.googleapis.com', $guest);
        $this->assertStringNotContainsString('fonts.gstatic.com', $guest);
        $csp = (string) $this->get('/login')->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('fonts.g', $csp, 'the policy names no font host');
    }

    public function test_the_shared_header_renders_one_row_with_no_inline_script_or_style(): void
    {
        $html = $this->actingAs($this->pulseUser())->get('/floor')->assertOk()->getContent();

        $this->assertStringContainsString('<header class="dh', $html);
        $this->assertStringNotContainsString('class="sh-mast', $html, 'the old masthead is gone');
        $this->assertStringContainsString('dh--nonav', $html, 'the verbs live in the rail and the phone bar, not twice');
        $this->assertSame(1, preg_match('/<header\b.*?<\/header>/s', $html, $bar));
        $this->assertStringNotContainsString(' style=', $bar[0]);
        $this->assertStringNotContainsString('<script', $bar[0]);
        $this->assertSame(1, substr_count($html, 'role="search"'));
    }

    public function test_the_search_is_in_the_header_of_every_page_type(): void
    {
        $user = $this->pulseUser();
        $this->actingAs($user);

        foreach (['/floor', '/trade', '/work', '/local', '/learn', '/search?q=plumb', '/lines', '/community', '/saved', '/me/profile', '/me/notifications', '/need/new', '/business/new', '/door'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertSame(1, preg_match('/<header\b.*?<\/header>/s', $html, $bar), $url);
            $this->assertStringContainsString('class="dsr-input"', $bar[0], "$url: search input in the header");
            $this->assertStringContainsString('data-dh-search-toggle', $bar[0], "$url: phone search button");
            $this->assertStringContainsString('name="q"', $bar[0], "$url: same parameter as /search");
        }
    }

    public function test_a_signed_out_page_has_the_shared_header_with_log_in_and_no_search(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<header class="dh', $html);
        $this->assertStringNotContainsString('class="dsr-input"', $html, 'search needs a person: the floor is theirs');
    }

    public function test_the_phone_reaches_search_saved_community_and_your_business_through_the_drawer(): void
    {
        $html = $this->actingAs($this->pulseUser())->get('/floor')->assertOk()->getContent();
        $drawer = substr($html, (int) strpos($html, 'class="dh-drawer"'));

        foreach (['Search', 'Saved', 'Community', 'Open door'] as $label) {
            $this->assertStringContainsString('<span class="dh-menu__label">'.$label.'</span>', $drawer);
        }
        $this->assertStringContainsString('data-dh-menu-button', $html);
        $this->assertSame(1, substr_count($html, '<nav class="sh-bar"'), 'the phone bar stays: five verbs, 44px and more each');
    }

    public function test_the_header_suggestions_are_the_viewers_own_scoped_view_not_a_new_search(): void
    {
        $me = $this->pulseUser();
        $other = User::factory()->withPersonalTeam()->create();
        $this->dEntity($other, ['name' => 'Plumb Perfect']);
        $this->dEntity($other, ['name' => 'Plumb Draft', 'status' => 'draft']);
        $blocked = User::factory()->withPersonalTeam()->create();
        $this->dBlock($me, $blocked);
        $this->dSignal(['title' => 'Plumbing from blocked', 'owner_id' => $blocked->id]);

        $page = Livewire::actingAs($me)->test(HeaderSearch::class)->call('touch')->set('query', 'plumb');

        $page->assertSee('Plumb Perfect')
            ->assertDontSee('Plumb Draft')
            ->assertDontSee('Plumbing from blocked')
            ->assertSee(route('search', ['q' => 'Plumb Perfect']), false);
    }

    public function test_the_header_search_asks_nothing_until_focused_and_says_so_when_nothing_matches(): void
    {
        $me = $this->pulseUser();

        Livewire::actingAs($me)->test(HeaderSearch::class)->set('query', 'plumb')->assertDontSee('dsr-quick', false);
        Livewire::actingAs($me)->test(HeaderSearch::class)->call('touch')->set('query', 'zzzzqx')->assertSee('No suggestion for', false);
        Livewire::actingAs($me)->test(HeaderSearch::class)->call('touch')->set('query', 'z')->assertDontSee('No suggestion');
    }

    public function test_the_account_menu_lists_teams_as_forms_and_has_the_theme_control(): void
    {
        $user = $this->pulseUser();
        $html = $this->actingAs($user)->get('/floor')->assertOk()->getContent();
        $menu = substr($html, (int) strpos($html, 'id="dh-account-menu"'), 6000);

        $this->assertStringContainsString('action="'.route('logout').'"', $menu);
        $this->assertStringContainsString('href="'.route('teams.show', $user->currentTeam).'"', $menu);
        $this->assertStringContainsString('data-dh-theme-set="system"', $menu);
    }
}
