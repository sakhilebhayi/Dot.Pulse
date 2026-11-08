<?php

namespace Tests\Feature\Me;

use App\Livewire\Pulse\FollowList;
use App\Livewire\Pulse\HomeFeed;
use App\Livewire\Pulse\Me\NotificationList;
use App\Livewire\Pulse\PostShow;
use App\Livewire\Pulse\SuggestedUsers;
use App\Models\PulseComment;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\User;
use App\Notifications\NewFollower;
use Livewire\Livewire;

/**
 * Where an anonymous professional could be named or linked outside the talent card: people lists, suggestions,
 * bylines and notifications. The talent card stays anonymous; a post they wrote is theirs and keeps their handle,
 * but never links to a page that does not exist for others, and never carries the headline.
 */
class AnonymousProfessionalSurfacesTest extends MeTestCase
{
    private function anonymousPro(string $name = 'Zanele Hidden'): User
    {
        $u = User::factory()->withPersonalTeam()->create(['name' => $name]);
        PulseProfile::query()->create([
            'user_id' => $u->id, 'role' => 'customer', 'discoverability' => 'anonymous', 'headline' => 'Senior welder in Gauteng',
            'age_confirmed_at' => now(), 'onboarded_at' => now(), 'community_points' => 999,
        ]);

        return $u->fresh();
    }

    private function open(string $name = 'Olivia Open'): User
    {
        $u = User::factory()->withPersonalTeam()->create(['name' => $name]);
        PulseProfile::query()->create(['user_id' => $u->id, 'role' => 'customer', 'discoverability' => 'open', 'headline' => 'Bookkeeper', 'age_confirmed_at' => now(), 'onboarded_at' => now()]);

        return $u->fresh();
    }

    public function test_the_helper_decides_in_one_place(): void
    {
        $pro = $this->anonymousPro();
        $open = $this->open();
        $viewer = $this->member();

        $this->assertTrue(PulseProfile::isHiddenFrom($pro, $viewer));
        $this->assertFalse(PulseProfile::isHiddenFrom($pro, $pro), 'they see themselves');
        $this->assertFalse(PulseProfile::isHiddenFrom($open, $viewer));
        $this->assertSame('A professional', PulseProfile::publicName($pro, $viewer));
        $this->assertSame('Zanele Hidden', PulseProfile::publicName($pro, $viewer, authored: true), 'their own post keeps their handle');
        $this->assertNull(PulseProfile::publicHeadline($pro, $viewer));
        $this->assertSame('Bookkeeper', PulseProfile::publicHeadline($open, $viewer));
        $this->assertFalse(PulseProfile::isLinkable($pro, $viewer));
    }

    public function test_follow_lists_never_name_link_or_describe_an_anonymous_professional(): void
    {
        $target = $this->member();
        $pro = $this->anonymousPro();
        $open = $this->open();
        $pro->following()->attach($target->id);
        $open->following()->attach($target->id);

        $html = Livewire::actingAs($this->member())->test(FollowList::class, ['user' => $target, 'mode' => 'followers'])
            ->assertSee('Olivia Open')->assertSee('Bookkeeper')
            ->assertDontSee('Zanele Hidden')->assertDontSee('Senior welder')->assertSee('A professional')
            ->html();

        $this->assertStringNotContainsString(route('users.show', $pro), $html);
        $this->assertStringContainsString(route('users.show', $open), $html);

        Livewire::actingAs($pro)->test(FollowList::class, ['user' => $target, 'mode' => 'followers'])->assertSee('Zanele Hidden');
    }

    public function test_suggestions_skip_anonymous_professionals_even_with_the_most_points(): void
    {
        $this->anonymousPro();
        $this->open();

        Livewire::actingAs($this->member())->test(SuggestedUsers::class)
            ->assertSee('Olivia Open')->assertDontSee('Zanele Hidden')->assertDontSee('Senior welder');
    }

    public function test_a_post_and_a_comment_by_an_anonymous_professional_keep_the_handle_but_do_not_dead_end_or_leak_the_card(): void
    {
        $pro = $this->anonymousPro();
        $viewer = $this->member();
        $post = PulsePost::create(['user_id' => $pro->id, 'team_id' => $pro->currentTeam->id, 'type' => 'discussion', 'body' => 'Welding tips for thin steel.', 'status' => 'published']);
        PulseComment::create(['pulse_post_id' => $post->id, 'user_id' => $pro->id, 'body' => 'And a comment of mine.']);

        $feed = Livewire::actingAs($viewer)->test(HomeFeed::class)->assertSee('Zanele Hidden')->html();
        $show = Livewire::actingAs($viewer)->test(PostShow::class, ['post' => $post])->assertSee('Zanele Hidden')->html();

        foreach ([$feed, $show] as $html) {
            $this->assertStringNotContainsString(route('users.show', $pro), $html, 'no link to a page that is a 404');
            $this->assertStringNotContainsString('Senior welder', $html, 'the talent headline never rides along');
        }

        $this->actingAs($viewer)->get(route('users.show', $pro))->assertNotFound();
        $this->actingAs($pro)->get(route('users.show', $pro))->assertOk();
    }

    public function test_the_follow_notification_does_not_name_or_link_an_anonymous_professional(): void
    {
        $me = $this->member();
        $pro = $this->anonymousPro();
        $me->notify(new NewFollower($pro));

        $html = Livewire::actingAs($me)->test(NotificationList::class)->assertSee('A professional')->assertDontSee('Zanele Hidden')->html();
        $this->assertStringNotContainsString(route('users.show', $pro), $html);
    }
}
