<?php

namespace Tests\Feature\Pulse;

use App\Livewire\Pulse\CommunityList;
use App\Livewire\Pulse\CreatePost;
use App\Livewire\Pulse\HomeFeed;
use App\Livewire\Pulse\PostShow;
use App\Livewire\Pulse\ReportButton;
use App\Support\PulseThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    public function test_the_default_limits_are_the_ones_the_owner_asked_for(): void
    {
        $this->assertSame(
            ['post' => 20, 'comment' => 30, 'react' => 60, 'report' => 10, 'join_request' => 10],
            config('pulse.rate_limits'),
        );
    }

    public function test_posting_is_limited_per_minute_with_a_429(): void
    {
        config(['pulse.rate_limits.post' => 3]);
        $user = $this->pulseUser();

        for ($i = 1; $i <= 3; $i++) {
            Livewire::actingAs($user)->test(CreatePost::class)
                ->set('body', "Post number {$i} with enough words to pass.")
                ->call('publish')
                ->assertHasNoErrors()
                ->assertOk();
        }

        Livewire::actingAs($user)->test(CreatePost::class)
            ->set('body', 'The fourth post inside the same minute.')
            ->call('publish')
            ->assertStatus(429);

        $this->assertDatabaseCount('pulse_posts', 3);
    }

    public function test_commenting_and_replying_share_one_comment_limit(): void
    {
        config(['pulse.rate_limits.comment' => 2]);
        $post = $this->postIn(null);
        $user = $this->pulseUser();

        $show = Livewire::actingAs($user)->test(PostShow::class, ['post' => $post]);
        $show->set('newCommentBody', 'One.')->call('postComment')->assertOk();
        $show->set('newCommentBody', 'Two.')->call('postComment')->assertOk();

        Livewire::actingAs($user)->test(PostShow::class, ['post' => $post])
            ->set('newCommentBody', 'Three.')->call('postComment')
            ->assertStatus(429);

        $this->assertSame(2, $post->fresh()->comments_count);
    }

    public function test_reacting_is_limited(): void
    {
        config(['pulse.rate_limits.react' => 3]);
        $post = $this->postIn(null);
        $user = $this->pulseUser();

        $feed = Livewire::actingAs($user)->test(HomeFeed::class);
        $feed->call('react', $post->id)->call('react', $post->id)->call('react', $post->id);

        Livewire::actingAs($user)->test(HomeFeed::class)->call('react', $post->id)->assertStatus(429);
    }

    public function test_reporting_is_limited_and_a_refused_report_stores_nothing(): void
    {
        config(['pulse.rate_limits.report' => 2]);
        $user = $this->pulseUser();

        foreach ([1, 2] as $_) {
            $post = $this->postIn(null);
            Livewire::actingAs($user)->test(ReportButton::class, ['targetType' => 'post', 'targetId' => $post->id])
                ->set('reason', 'spam')->call('submit')->assertOk();
        }

        $third = $this->postIn(null);
        Livewire::actingAs($user)->test(ReportButton::class, ['targetType' => 'post', 'targetId' => $third->id])
            ->set('reason', 'spam')->call('submit')->assertStatus(429);

        $this->assertDatabaseCount('pulse_reports', 2);
    }

    public function test_membership_requests_are_limited(): void
    {
        config(['pulse.rate_limits.join_request' => 2]);
        $user = $this->pulseUser();
        $communities = collect(range(1, 3))->map(fn () => $this->privateCommunity());

        $list = Livewire::actingAs($user)->test(CommunityList::class);
        $list->call('join', $communities[0]->id)->call('join', $communities[1]->id);

        Livewire::actingAs($user)->test(CommunityList::class)
            ->call('join', $communities[2]->id)
            ->assertStatus(429);
    }

    public function test_one_users_limit_does_not_slow_anyone_else(): void
    {
        config(['pulse.rate_limits.react' => 1]);
        $post = $this->postIn(null);
        $busy = $this->pulseUser();
        $other = $this->pulseUser();

        Livewire::actingAs($busy)->test(HomeFeed::class)->call('react', $post->id)->assertOk();
        Livewire::actingAs($busy)->test(HomeFeed::class)->call('react', $post->id)->assertStatus(429);
        Livewire::actingAs($other)->test(HomeFeed::class)->call('react', $post->id)->assertOk();
    }

    public function test_the_limit_window_is_one_minute(): void
    {
        config(['pulse.rate_limits.react' => 1]);
        $post = $this->postIn(null);
        $user = $this->pulseUser();

        Livewire::actingAs($user)->test(HomeFeed::class)->call('react', $post->id)->assertOk();
        Livewire::actingAs($user)->test(HomeFeed::class)->call('react', $post->id)->assertStatus(429);

        $this->travel(61)->seconds();

        Livewire::actingAs($user)->test(HomeFeed::class)->call('react', $post->id)->assertOk();
    }

    public function test_the_429_carries_a_retry_after_header(): void
    {
        $exception = PulseThrottle::exception(37);

        $this->assertSame(429, $exception->getStatusCode());
        $this->assertSame('37', $exception->getHeaders()['Retry-After']);
    }
}
