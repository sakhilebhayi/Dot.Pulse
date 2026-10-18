<?php

namespace Tests\Feature\Pulse;

use App\Livewire\Pulse\CommunityList;
use App\Livewire\Pulse\HomeFeed;
use App\Livewire\Pulse\TrendingHashtags;
use App\Models\PulseHashtag;
use App\Models\PulsePost;
use App\Services\HashtagExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

class PrivateCommunityVisibilityTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    private const SECRET_TITLE = 'Quarterly layoffs planning for the vault group';

    private const SECRET_BODY = 'We will announce the vault group restructuring on Friday afternoon.';

    public function test_community_visibility_rule_is_defined_in_one_place(): void
    {
        $public = $this->publicCommunity();
        $private = $this->privateCommunity();
        $member = $this->joined($private);
        $stranger = $this->pulseUser();

        $this->assertTrue($public->isVisibleTo($stranger));
        $this->assertTrue($public->isVisibleTo(null));
        $this->assertTrue($private->isVisibleTo($member));
        $this->assertFalse($private->isVisibleTo($stranger));
        $this->assertFalse($private->isVisibleTo(null));
        $this->assertTrue($private->isMember($member));
        $this->assertFalse($private->isMember($stranger));
        // 'enterprise' is not public either.
        $enterprise = $this->publicCommunity(['visibility' => 'enterprise']);
        $this->assertFalse($enterprise->isVisibleTo($stranger));
    }

    public function test_the_private_community_page_is_a_404_for_non_members(): void
    {
        $private = $this->privateCommunity();
        $stranger = $this->pulseUser();

        $this->actingAs($stranger)->get(route('communities.show', $private))->assertNotFound();
    }

    public function test_the_private_community_page_renders_for_members_and_for_public_communities(): void
    {
        $private = $this->privateCommunity(['name' => 'Vault Group Private Room']);
        $member = $this->joined($private);
        $this->postIn($private, ['title' => self::SECRET_TITLE, 'body' => self::SECRET_BODY]);

        $this->actingAs($member)->get(route('communities.show', $private))
            ->assertOk()
            ->assertSee(self::SECRET_TITLE);

        $public = $this->publicCommunity();
        $this->actingAs($this->pulseUser())->get(route('communities.show', $public))->assertOk();
    }

    public function test_a_private_post_page_is_a_404_for_non_members_and_ok_for_members(): void
    {
        $private = $this->privateCommunity();
        $member = $this->joined($private);
        $post = $this->postIn($private, ['title' => self::SECRET_TITLE]);

        $this->actingAs($this->pulseUser())->get(route('posts.show', $post))->assertNotFound();
        $this->actingAs($member)->get(route('posts.show', $post))->assertOk()->assertSee(self::SECRET_TITLE);
    }

    public function test_the_feed_hides_private_community_posts_from_non_members(): void
    {
        $private = $this->privateCommunity();
        $member = $this->joined($private);
        $this->postIn($private, ['title' => self::SECRET_TITLE, 'body' => self::SECRET_BODY]);
        $this->postIn(null, ['title' => 'A perfectly public hello from nobody in particular']);

        Livewire::actingAs($this->pulseUser())->test(HomeFeed::class)
            ->assertDontSee(self::SECRET_TITLE)
            ->assertDontSee(self::SECRET_BODY)
            ->assertSee('A perfectly public hello from nobody in particular');

        Livewire::actingAs($member)->test(HomeFeed::class)
            ->assertSee(self::SECRET_TITLE);
    }

    public function test_following_a_private_posters_account_does_not_expose_their_private_posts(): void
    {
        $private = $this->privateCommunity();
        $poster = $this->joined($private);
        $this->postIn($private, ['user_id' => $poster->id, 'title' => self::SECRET_TITLE]);

        $follower = $this->pulseUser();
        $follower->following()->attach($poster->id);

        Livewire::actingAs($follower)->test(HomeFeed::class)
            ->set('followingOnly', true)
            ->assertDontSee(self::SECRET_TITLE);
    }

    public function test_the_hashtag_filter_and_trending_ignore_private_posts_for_non_members(): void
    {
        $private = $this->privateCommunity();
        $member = $this->joined($private);
        $post = $this->postIn($private, ['title' => self::SECRET_TITLE, 'body' => 'Plans for #vaultgroupsecret are final.']);
        (new HashtagExtractionService)->extractAndAttach($post);
        $this->assertTrue(PulseHashtag::where('name', 'vaultgroupsecret')->exists());

        $stranger = $this->pulseUser();

        Livewire::actingAs($stranger)->test(TrendingHashtags::class)->assertDontSee('vaultgroupsecret');
        Livewire::actingAs($member)->test(TrendingHashtags::class)->assertSee('vaultgroupsecret');

        Livewire::actingAs($stranger)->test(HomeFeed::class)
            ->call('filterByHashtag', 'vaultgroupsecret')
            ->assertDontSee(self::SECRET_TITLE);
    }

    public function test_profile_pages_do_not_list_private_posts_to_non_members(): void
    {
        $private = $this->privateCommunity();
        $poster = $this->joined($private);
        $this->postIn($private, ['user_id' => $poster->id, 'title' => self::SECRET_TITLE]);

        $this->actingAs($this->pulseUser())->get(route('users.show', $poster))
            ->assertOk()->assertDontSee(self::SECRET_TITLE);
        $this->actingAs($poster)->get(route('users.show', $poster))
            ->assertOk()->assertSee(self::SECRET_TITLE);
    }

    public function test_the_directory_lists_private_communities_without_their_description_or_a_link(): void
    {
        $private = $this->privateCommunity([
            'name' => 'Vault Group Private Room',
            'description' => 'Internal description that outsiders must never read.',
        ]);

        Livewire::actingAs($this->pulseUser())->test(CommunityList::class)
            ->assertSee('Vault Group Private Room')
            ->assertDontSee('Internal description that outsiders must never read.')
            ->assertSee('Request to join')
            ->assertDontSeeHtml(route('communities.show', $private));
    }

    public function test_the_dashboard_post_total_does_not_count_private_posts_the_viewer_cannot_see(): void
    {
        $private = $this->privateCommunity();
        $this->postIn($private);
        $this->postIn($private);
        $this->postIn(null);

        $stranger = $this->pulseUser();
        $response = $this->actingAs($stranger)->get('/dashboard')->assertOk();

        $this->assertSame(1, $response->viewData('totalPosts'));
        $this->assertSame(1, PulsePost::visibleTo($stranger)->published()->count());
        $this->assertSame(3, PulsePost::published()->count());
    }
}
