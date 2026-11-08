<?php

namespace Tests\Feature\Pulse;

use App\Events\CommentPosted;
use App\Livewire\Pulse\FollowButton;
use App\Livewire\Pulse\PostShow;
use App\Models\PulseComment;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Threaded comments — pulse_comments has existed since the platform's
 * first migration (parent_id self-reference, is_solution, reactions_count)
 * with no UI or controller ever reading/writing it.
 */
class PostShowTest extends TestCase
{
    use RefreshDatabase;

    private function publishedPost(?User $author = null): PulsePost
    {
        $author ??= User::factory()->withPersonalTeam()->create();

        return PulsePost::create([
            'user_id' => $author->id,
            'team_id' => $author->currentTeam->id,
            'type' => 'question',
            'title' => 'How do I wire up webhooks?',
            'body' => 'Struggling with the inbound webhook flow.',
            'status' => 'published',
        ]);
    }

    public function test_a_published_post_page_is_viewable(): void
    {
        $post = $this->publishedPost();
        $viewer = User::factory()->withPersonalTeam()->create();

        $this->actingAs($this->onboard($viewer))
            ->get("/posts/{$post->id}")
            ->assertOk();
    }

    public function test_the_post_page_embeds_a_follow_button_for_the_author(): void
    {
        $post = $this->publishedPost();
        $viewer = User::factory()->withPersonalTeam()->create();

        $this->actingAs($this->onboard($viewer))
            ->get("/posts/{$post->id}")
            ->assertSeeLivewire(FollowButton::class);
    }

    public function test_the_post_page_renders_without_error_when_a_comment_is_from_a_different_author(): void
    {
        $post = $this->publishedPost();
        $commenter = User::factory()->withPersonalTeam()->create();
        $post->comments()->create(['user_id' => $commenter->id, 'body' => 'A reply from someone else']);
        $viewer = User::factory()->withPersonalTeam()->create();

        $this->actingAs($this->onboard($viewer))
            ->get("/posts/{$post->id}")
            ->assertOk()
            ->assertSee('A reply from someone else');
    }

    public function test_a_held_post_is_not_viewable_by_a_stranger(): void
    {
        $author = User::factory()->withPersonalTeam()->create();
        $post = PulsePost::create([
            'user_id' => $author->id,
            'team_id' => $author->currentTeam->id,
            'type' => 'discussion',
            'body' => 'Held for review.',
            'status' => 'flagged',
        ]);
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($this->onboard($stranger))
            ->get("/posts/{$post->id}")
            ->assertNotFound();
    }

    public function test_a_held_post_is_still_viewable_by_its_own_author(): void
    {
        $author = User::factory()->withPersonalTeam()->create();
        $post = PulsePost::create([
            'user_id' => $author->id,
            'team_id' => $author->currentTeam->id,
            'type' => 'discussion',
            'body' => 'Held for review.',
            'status' => 'flagged',
        ]);

        $this->actingAs($this->onboard($author))
            ->get("/posts/{$post->id}")
            ->assertOk();
    }

    public function test_a_user_can_post_a_top_level_comment(): void
    {
        Event::fake([CommentPosted::class]);
        $post = $this->publishedPost();
        $commenter = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($commenter)
            ->test(PostShow::class, ['post' => $post])
            ->set('newCommentBody', 'Have you tried the /webhooks/{token} endpoint?')
            ->call('postComment')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('pulse_comments', [
            'pulse_post_id' => $post->id,
            'user_id' => $commenter->id,
            'body' => 'Have you tried the /webhooks/{token} endpoint?',
        ]);
        $this->assertSame(1, $post->fresh()->comments_count);
        Event::assertDispatched(CommentPosted::class, fn ($event) => $event->comment->body === 'Have you tried the /webhooks/{token} endpoint?');
    }

    public function test_a_blank_comment_is_rejected(): void
    {
        $post = $this->publishedPost();
        $commenter = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($commenter)
            ->test(PostShow::class, ['post' => $post])
            ->set('newCommentBody', '')
            ->call('postComment')
            ->assertHasErrors(['newCommentBody']);

        $this->assertDatabaseCount('pulse_comments', 0);
    }

    public function test_a_user_can_reply_to_a_comment(): void
    {
        Event::fake([CommentPosted::class]);
        $post = $this->publishedPost();
        $commenter = User::factory()->withPersonalTeam()->create();
        $replier = User::factory()->withPersonalTeam()->create();

        $comment = $post->comments()->create(['user_id' => $commenter->id, 'body' => 'Top level.']);

        Livewire::actingAs($replier)
            ->test(PostShow::class, ['post' => $post])
            ->call('startReply', $comment->id)
            ->set('replyBody', 'Following up on this.')
            ->call('postReply', $comment->id)
            ->assertHasNoErrors();

        Event::assertDispatched(CommentPosted::class, fn ($event) => $event->comment->body === 'Following up on this.');

        $this->assertDatabaseHas('pulse_comments', [
            'parent_id' => $comment->id,
            'user_id' => $replier->id,
            'body' => 'Following up on this.',
        ]);
        $this->assertSame(1, $post->fresh()->comments_count);
    }

    public function test_reacting_to_a_comment_toggles_it(): void
    {
        $post = $this->publishedPost();
        $author = User::factory()->withPersonalTeam()->create();
        $reactor = User::factory()->withPersonalTeam()->create();
        $comment = $post->comments()->create(['user_id' => $author->id, 'body' => 'A comment.']);

        $component = Livewire::actingAs($reactor)->test(PostShow::class, ['post' => $post]);

        $component->call('react', $comment->id);
        $this->assertSame(1, $comment->fresh()->reactions_count);
        $this->assertDatabaseHas('pulse_reactions', [
            'user_id' => $reactor->id,
            'reactable_type' => PulseComment::class,
            'reactable_id' => $comment->id,
        ]);

        $component->call('react', $comment->id);
        $this->assertSame(0, $comment->fresh()->reactions_count);
    }

    public function test_the_post_author_can_mark_a_comment_as_the_solution(): void
    {
        $author = User::factory()->withPersonalTeam()->create();
        $post = $this->publishedPost($author);
        $answerer = User::factory()->withPersonalTeam()->create();
        PulseProfile::create(['user_id' => $answerer->id, 'community_points' => 0, 'solutions_accepted' => 0]);
        $comment = $post->comments()->create(['user_id' => $answerer->id, 'body' => 'Here is the fix.']);

        Livewire::actingAs($author)
            ->test(PostShow::class, ['post' => $post])
            ->call('markSolution', $comment->id);

        $this->assertTrue($comment->fresh()->is_solution);
        $profile = $answerer->pulseProfile()->first();
        $this->assertSame(10, $profile->community_points);
        $this->assertSame(1, $profile->solutions_accepted);
    }

    public function test_marking_a_new_solution_unmarks_the_previous_one(): void
    {
        $author = User::factory()->withPersonalTeam()->create();
        $post = $this->publishedPost($author);
        $answerer = User::factory()->withPersonalTeam()->create();
        $first = $post->comments()->create(['user_id' => $answerer->id, 'body' => 'First answer.', 'is_solution' => true]);
        $second = $post->comments()->create(['user_id' => $answerer->id, 'body' => 'Better answer.']);

        Livewire::actingAs($author)
            ->test(PostShow::class, ['post' => $post])
            ->call('markSolution', $second->id);

        $this->assertFalse($first->fresh()->is_solution);
        $this->assertTrue($second->fresh()->is_solution);
    }

    public function test_a_non_author_cannot_mark_a_solution(): void
    {
        $post = $this->publishedPost();
        $stranger = User::factory()->withPersonalTeam()->create();
        $comment = $post->comments()->create(['user_id' => $stranger->id, 'body' => 'A comment.']);

        Livewire::actingAs($stranger)
            ->test(PostShow::class, ['post' => $post])
            ->call('markSolution', $comment->id)
            ->assertStatus(403);
    }

    public function test_the_comment_posted_browser_event_refreshes_the_comment_list(): void
    {
        $post = $this->publishedPost();
        $author = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();

        $component = Livewire::actingAs($author)
            ->test(PostShow::class, ['post' => $post])
            ->assertDontSee('Arrived out of band.');

        // Simulate a comment arriving from another user's browser tab --
        // resources/js/pulse-realtime.js dispatches this browser event on
        // a real App\Events\CommentPosted broadcast.
        $post->comments()->create(['user_id' => $other->id, 'body' => 'Arrived out of band.']);

        $component->dispatch('comment-posted')->assertSee('Arrived out of band.');
    }
}
