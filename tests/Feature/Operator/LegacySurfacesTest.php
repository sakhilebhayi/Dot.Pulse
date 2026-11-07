<?php

namespace Tests\Feature\Operator;

use App\Livewire\Pulse\CommunityList;
use App\Livewire\Pulse\CommunityShow;
use App\Livewire\Pulse\CreatePost;
use App\Livewire\Pulse\FollowList;
use App\Livewire\Pulse\HomeFeed;
use App\Livewire\Pulse\MyPostStatus;
use App\Livewire\Pulse\PostShow;
use App\Livewire\Pulse\ReportButton;
use App\Livewire\Pulse\SuggestedUsers;
use App\Livewire\Pulse\TrendingHashtags;
use App\Models\PulsePost;
use App\Models\PulseReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

/**
 * The community surfaces after the Floor restyle: same behaviour, kit markup, no legacy icon font, no inline dark
 * theme colours, no dashes or exclamation marks in the copy.
 */
class LegacySurfacesTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    private function aPost(array $attrs = []): PulsePost
    {
        return PulsePost::factory()->create(array_merge(['status' => 'published', 'published_at' => now(), 'title' => 'A distinctive restyled post title', 'body' => 'Body of the restyled post for the checks.'], $attrs));
    }

    public function test_the_dashboard_is_the_floor_column_with_trending_in_the_context_column(): void
    {
        $user = $this->pulseUser();
        $this->aPost();

        $this->actingAs($user)->get('/community')->assertOk()
            ->assertSee('sh-stage--floor', false)
            ->assertSee('Trending')
            ->assertSee('A distinctive restyled post title')
            ->assertSee('Share something with the community');
    }

    public function test_the_feed_renders_posts_as_kit_cards_and_reacts(): void
    {
        $user = $this->pulseUser();
        $post = $this->aPost();

        Livewire::actingAs($user)->test(HomeFeed::class)
            ->assertSeeHtml('pk-card lg-post')
            ->assertSee('Helpful')
            ->assertSee('Comments')
            ->call('react', $post->id);

        $this->assertSame(1, $post->fresh()->reactions_count);
    }

    public function test_an_empty_feed_says_what_to_do(): void
    {
        Livewire::actingAs($this->pulseUser())->test(HomeFeed::class)
            ->assertSee('No posts yet. Be the first to share something with the community.');
    }

    public function test_creating_a_post_still_works_through_the_new_form(): void
    {
        $user = $this->pulseUser();

        Livewire::actingAs($user)->test(CreatePost::class)
            ->set('showForm', true)
            ->assertSeeHtml('id="livewire-pulse-create-post-body"')
            ->set('type', 'discussion')
            ->set('body', 'A short, plain post written through the restyled form.')
            ->call('publish')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('pulse_posts', ['user_id' => $user->id, 'body' => 'A short, plain post written through the restyled form.']);
    }

    public function test_a_post_page_comments_and_reports_through_the_new_controls(): void
    {
        $viewer = $this->pulseUser();
        $post = $this->aPost();

        Livewire::actingAs($viewer)->test(PostShow::class, ['post' => $post])
            ->assertSee('Write a comment')
            ->set('newCommentBody', 'A comment that goes through the restyled form.')
            ->call('postComment')
            ->assertSee('A comment that goes through the restyled form.');

        Livewire::actingAs($viewer)->test(ReportButton::class, ['targetType' => 'post', 'targetId' => $post->id])
            ->call('toggle')
            ->assertSee('What is wrong with this post?')
            ->assertSee('Moderators see your note, not your name.')
            ->set('reason', 'spam')
            ->call('submit')
            ->assertSee('Reported');

        $this->assertSame(1, PulseReport::where('reportable_id', $post->id)->count());
    }

    public function test_communities_list_shows_the_private_mark_without_the_icon_font(): void
    {
        $user = $this->pulseUser();
        $this->privateCommunity(['name' => 'Secret Room For The Test']);

        $html = Livewire::actingAs($user)->test(CommunityList::class)
            ->assertSee('Secret Room For The Test')
            ->assertSee('Request to join')
            ->assertSee('Private')
            ->html();

        $this->assertStringNotContainsString('material-symbols', $html);
    }

    public function test_joining_and_leaving_a_community_still_works(): void
    {
        $user = $this->pulseUser();
        $community = $this->publicCommunity(['name' => 'Open Room For The Test']);

        Livewire::actingAs($user)->test(CommunityList::class)
            ->assertSee('Join')
            ->call('join', $community->id)
            ->assertSee('Leave')
            ->call('leave', $community->id)
            ->assertSee('Join');
    }

    public function test_a_community_page_has_no_icon_font_and_shows_its_posts(): void
    {
        $user = $this->pulseUser();
        $community = $this->privateCommunity(['name' => 'Member Only Room']);
        $this->joined($community, $user);
        $this->aPost(['community_id' => $community->id, 'title' => 'A post inside the member room']);

        $html = Livewire::actingAs($user)->test(CommunityShow::class, ['community' => $community])
            ->assertSee('A post inside the member room')
            ->assertSee('Leave')
            ->html();

        $this->assertStringNotContainsString('material-symbols', $html);
        $this->assertStringContainsString('pk-card', $html);
    }

    public function test_none_of_the_restyled_views_use_inline_dark_theme_colours_or_the_icon_font(): void
    {
        $files = [
            'livewire/pulse/home-feed', 'livewire/pulse/create-post', 'livewire/pulse/post-show', 'livewire/pulse/partials/comment',
            'livewire/pulse/community-list', 'livewire/pulse/community-show', 'livewire/pulse/follow-list', 'livewire/pulse/suggested-users',
            'livewire/pulse/trending-hashtags', 'livewire/pulse/my-post-status', 'livewire/pulse/report-button', 'livewire/pulse/follow-button',
            'livewire/pulse/moderation-queue', 'livewire/pulse/queue/group', 'livewire/pulse/queue/flags', 'livewire/pulse/operator/dashboard',
            'dashboard', 'pulse/post-show', 'pulse/community-show', 'pulse/moderation', 'pulse/operator',
        ];

        foreach ($files as $file) {
            $source = file_get_contents(resource_path("views/{$file}.blade.php"));
            $this->assertDoesNotMatchRegularExpression('/#(f4f4f5|a1a1aa|71717a|52525b|3f3f46|f1c62e|f87171)/i', $source, "{$file} still has an old inline colour");
            $this->assertStringNotContainsString('material-symbols', $source, "{$file} still uses the icon font");
            $this->assertStringNotContainsString("font-family:'Syne'", $source, $file);
            $this->assertStringNotContainsString('dot-card', $source, $file);
        }
    }

    public function test_people_lists_and_trending_render_on_the_kit(): void
    {
        $user = $this->pulseUser();
        $other = $this->pulseUser();
        $user->following()->attach($other->id);

        Livewire::actingAs($user)->test(FollowList::class, ['user' => $user, 'mode' => 'following'])
            ->assertSee($other->name)
            ->assertSeeHtml('pk-row');

        Livewire::actingAs($user)->test(FollowList::class, ['user' => $other, 'mode' => 'following'])
            ->assertSee('Not following anyone yet.');

        Livewire::actingAs($user)->test(SuggestedUsers::class)->assertSee('No suggestions yet. Check back once more people join.');
        Livewire::actingAs($user)->test(TrendingHashtags::class)->assertSee('Nothing trending yet.');
        Livewire::actingAs($user)->test(MyPostStatus::class)->assertOk();
    }
}
