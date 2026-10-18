<?php

namespace Tests\Feature\Pulse;

use App\Livewire\Pulse\CreatePost;
use App\Livewire\Pulse\HomeFeed;
use App\Livewire\Pulse\PostShow;
use App\Models\PulseComment;
use App\Models\PulseModerationLog;
use App\Models\PulsePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

class PostAuthorizationTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    public function test_a_user_cannot_post_into_a_private_community_they_do_not_belong_to(): void
    {
        $private = $this->privateCommunity();
        $stranger = $this->pulseUser();

        Livewire::actingAs($stranger)->test(CreatePost::class)
            ->set('body', 'Trying to sneak into somewhere I do not belong.')
            ->set('communityId', $private->id)
            ->call('publish')
            ->assertHasErrors('communityId');

        $this->assertSame(0, PulsePost::count());
    }

    public function test_posting_into_any_community_requires_membership(): void
    {
        $public = $this->publicCommunity();
        $user = $this->pulseUser();

        Livewire::actingAs($user)->test(CreatePost::class)
            ->set('body', 'I have not joined this community yet at all.')
            ->set('communityId', $public->id)
            ->call('publish')
            ->assertHasErrors('communityId');

        $public->addMember($user);

        Livewire::actingAs($user)->test(CreatePost::class)
            ->set('body', 'Now I have joined, so this should go through fine.')
            ->set('communityId', $public->id)
            ->call('publish')
            ->assertHasNoErrors();

        $this->assertSame(1, PulsePost::where('community_id', $public->id)->count());
    }

    public function test_the_composer_only_offers_communities_the_user_belongs_to(): void
    {
        $mine = $this->publicCommunity(['name' => 'Community I belong to Alpha']);
        $theirs = $this->publicCommunity(['name' => 'Community I never joined Beta']);
        $user = $this->pulseUser();
        $mine->addMember($user);

        Livewire::actingAs($user)->test(CreatePost::class)
            ->set('showForm', true)
            ->assertSee('Community I belong to Alpha')
            ->assertDontSee('Community I never joined Beta');
    }

    public function test_posting_works_for_a_user_with_no_current_team(): void
    {
        $user = $this->pulseUser(withTeam: false);
        $this->assertNull($user->currentTeam);

        Livewire::actingAs($user)->test(CreatePost::class)
            ->set('body', 'A post from someone who has no team at all.')
            ->call('publish')
            ->assertHasNoErrors();

        $post = PulsePost::firstOrFail();
        $this->assertNull($post->team_id);
        $this->assertSame($user->id, $post->user_id);
    }

    #[DataProvider('unreactableStatuses')]
    public function test_nobody_can_react_to_a_post_that_is_not_published(string $status): void
    {
        $post = $this->postIn(null, ['status' => $status]);
        $viewer = $this->pulseUser();

        Livewire::actingAs($viewer)->test(HomeFeed::class)
            ->call('react', $post->id)
            ->assertNotFound();

        $this->assertSame(0, $post->fresh()->reactions_count);
        $this->assertDatabaseCount('pulse_reactions', 0);
    }

    public static function unreactableStatuses(): array
    {
        return [['pending'], ['flagged'], ['removed'], ['draft']];
    }

    public function test_a_user_cannot_react_to_a_private_community_post_they_cannot_see(): void
    {
        $private = $this->privateCommunity();
        $post = $this->postIn($private);

        Livewire::actingAs($this->pulseUser())->test(HomeFeed::class)
            ->call('react', $post->id)
            ->assertNotFound();

        $this->assertDatabaseCount('pulse_reactions', 0);
    }

    public function test_a_member_can_react_to_a_private_post_and_toggle_it_off(): void
    {
        $private = $this->privateCommunity();
        $member = $this->joined($private);
        $post = $this->postIn($private);

        $feed = Livewire::actingAs($member)->test(HomeFeed::class);
        $feed->call('react', $post->id);
        $this->assertSame(1, $post->fresh()->reactions_count);

        $feed->call('react', $post->id);
        $this->assertSame(0, $post->fresh()->reactions_count);
    }

    public function test_only_allowlisted_emoji_are_stored(): void
    {
        $post = $this->postIn(null);

        Livewire::actingAs($this->pulseUser())->test(HomeFeed::class)
            ->call('react', $post->id, '<script>alert(1)</script>')
            ->assertStatus(422);

        $this->assertDatabaseCount('pulse_reactions', 0);
    }

    public function test_commenting_on_a_post_the_viewer_cannot_see_or_that_is_not_published_is_refused(): void
    {
        $author = $this->pulseUser();
        $held = $this->postIn(null, ['status' => 'flagged', 'user_id' => $author->id]);

        // The author can open their own held post...
        $component = Livewire::actingAs($author)->test(PostShow::class, ['post' => $held]);
        // ...but nobody, the author included, can comment on it until it is live.
        $component->set('newCommentBody', 'Commenting on my own held post.')
            ->call('postComment')
            ->assertForbidden();

        $this->assertSame(0, PulseComment::count());

        $private = $this->privateCommunity();
        $post = $this->postIn($private);
        Livewire::actingAs($this->pulseUser())->test(PostShow::class, ['post' => $post])->assertNotFound();
    }

    public function test_a_member_can_comment_react_and_reply_on_a_private_post(): void
    {
        $private = $this->privateCommunity();
        $member = $this->joined($private);
        $post = $this->postIn($private);

        $component = Livewire::actingAs($member)->test(PostShow::class, ['post' => $post])
            ->set('newCommentBody', 'A thoughtful comment from a member of the group.')
            ->call('postComment')
            ->assertHasNoErrors();

        $comment = PulseComment::firstOrFail();
        $component->call('react', $comment->id);
        $component->set('replyBody', 'And a reply that follows right after it.')->call('postReply', $comment->id);

        $this->assertSame(2, $post->fresh()->comments_count);
        $this->assertSame(1, $comment->fresh()->reactions_count);
    }

    public function test_reacting_to_a_deleted_comment_is_a_404(): void
    {
        $post = $this->postIn(null);
        $comment = $this->commentOn($post);
        $comment->remove();

        Livewire::actingAs($this->pulseUser())->test(PostShow::class, ['post' => $post])
            ->call('react', $comment->id)
            ->assertNotFound();
    }

    public function test_mark_solution_requires_the_post_author_and_a_published_post(): void
    {
        $author = $this->pulseUser();
        $post = $this->postIn(null, ['user_id' => $author->id]);
        $comment = $this->commentOn($post);

        Livewire::actingAs($this->pulseUser())->test(PostShow::class, ['post' => $post])
            ->call('markSolution', $comment->id)
            ->assertForbidden();

        $post->update(['status' => 'flagged']);

        Livewire::actingAs($author)->test(PostShow::class, ['post' => $post->fresh()])
            ->call('markSolution', $comment->id)
            ->assertForbidden();

        $this->assertFalse($comment->fresh()->is_solution);
    }

    public function test_an_author_can_edit_their_post_inside_the_window_and_not_after(): void
    {
        config(['pulse.edit_window_minutes' => 30]);
        $author = $this->pulseUser();
        $post = $this->postIn(null, ['user_id' => $author->id, 'body' => 'The original wording of this post.']);

        $this->assertTrue(Gate::forUser($author)->allows('update', $post));
        $this->assertFalse(Gate::forUser($this->pulseUser())->allows('update', $post));

        $this->travel(31)->minutes();

        $this->assertFalse(Gate::forUser($author->fresh())->allows('update', $post->fresh()));
    }

    public function test_the_edit_window_can_be_switched_off(): void
    {
        config(['pulse.edit_window_minutes' => 0]);
        $author = $this->pulseUser();
        $post = $this->postIn(null, ['user_id' => $author->id]);

        $this->assertFalse(Gate::forUser($author)->allows('update', $post));
        $this->assertTrue(Gate::forUser($author)->allows('delete', $post));
    }

    public function test_an_author_deleting_their_post_soft_deletes_it_everywhere(): void
    {
        $author = $this->pulseUser();
        $post = $this->postIn(null, ['user_id' => $author->id, 'title' => 'A title that will vanish from every list']);

        Livewire::actingAs($author)->test(PostShow::class, ['post' => $post])
            ->call('deletePost')
            ->assertRedirect(route('dashboard'));

        $this->assertSoftDeleted('pulse_posts', ['id' => $post->id]);
        $this->actingAs($this->pulseUser())->get(route('posts.show', $post->id))->assertNotFound();
        Livewire::actingAs($this->pulseUser())->test(HomeFeed::class)
            ->assertDontSee('A title that will vanish from every list');
    }

    public function test_a_stranger_cannot_edit_or_delete_someone_elses_post_or_comment(): void
    {
        $post = $this->postIn(null);
        $comment = $this->commentOn($post);
        $stranger = $this->pulseUser();

        // A refused action leaves a component unusable, so one each.
        Livewire::actingAs($stranger)->test(PostShow::class, ['post' => $post])
            ->call('deletePost')->assertForbidden();
        Livewire::actingAs($stranger)->test(PostShow::class, ['post' => $post])
            ->call('deleteComment', $comment->id)->assertForbidden();

        $this->assertNull($post->fresh()->deleted_at);
        $this->assertNull($comment->fresh()->deleted_at);
    }

    public function test_a_comment_author_can_edit_and_delete_their_own_comment(): void
    {
        $post = $this->postIn(null);
        $commenter = $this->pulseUser();
        $comment = $this->commentOn($post, ['user_id' => $commenter->id, 'body' => 'Before the edit happened.']);
        $post->update(['comments_count' => 1]);

        $component = Livewire::actingAs($commenter)->test(PostShow::class, ['post' => $post]);
        $component->call('startEditComment', $comment->id)
            ->set('editBody', 'After the edit happened, much better.')
            ->call('saveEdit');

        $this->assertSame('After the edit happened, much better.', $comment->fresh()->body);
        $this->assertNotNull($comment->fresh()->edited_at);

        $component->call('deleteComment', $comment->id);

        $this->assertSoftDeleted('pulse_comments', ['id' => $comment->id]);
        $this->assertSame(0, $post->fresh()->comments_count);
    }

    public function test_deleting_a_comment_takes_its_replies_with_it_and_keeps_counts_true(): void
    {
        $post = $this->postIn(null);
        $parent = $this->commentOn($post);
        $reply1 = $this->commentOn($post, ['parent_id' => $parent->id]);
        $reply2 = $this->commentOn($post, ['parent_id' => $parent->id]);
        $other = $this->commentOn($post);
        PulsePost::syncCommentsCount($post->id);
        $this->assertSame(4, $post->fresh()->comments_count);

        $parent->remove();

        $this->assertSoftDeleted('pulse_comments', ['id' => $reply1->id]);
        $this->assertSoftDeleted('pulse_comments', ['id' => $reply2->id]);
        $this->assertNull($other->fresh()->deleted_at);
        $this->assertSame(1, $post->fresh()->comments_count);
    }

    public function test_a_hard_deleted_parent_no_longer_orphans_its_replies(): void
    {
        $post = $this->postIn(null);
        $parent = $this->commentOn($post);
        $reply = $this->commentOn($post, ['parent_id' => $parent->id]);

        $parent->forceDelete();

        $this->assertDatabaseMissing('pulse_comments', ['id' => $reply->id]);
    }

    public function test_a_platform_moderator_can_remove_a_post_and_the_log_row_is_kept(): void
    {
        $post = $this->postIn(null, ['title' => 'A post that will be taken down by staff']);
        $moderator = $this->platformModerator();

        Livewire::actingAs($moderator)->test(PostShow::class, ['post' => $post])
            ->set('removeReason', 'Breaks the harassment rules.')
            ->call('removeAsModerator');

        $this->assertSame('removed', $post->fresh()->status);
        $this->assertDatabaseHas('pulse_moderation_logs', [
            'target_type' => PulsePost::class,
            'target_id' => $post->id,
            'action' => 'remove',
            'rationale' => 'Breaks the harassment rules.',
            'moderator_id' => $moderator->id,
            'is_ai_decision' => false,
        ]);
    }

    public function test_a_community_moderator_removes_a_comment_in_their_community_but_not_elsewhere(): void
    {
        $mine = $this->privateCommunity();
        $moderator = $this->joined($mine, null, 'moderator');
        $post = $this->postIn($mine);
        $comment = $this->commentOn($post);

        Livewire::actingAs($moderator)->test(PostShow::class, ['post' => $post])
            ->set('removeReason', 'Off topic and unkind.')
            ->call('removeCommentAsModerator', $comment->id);

        $this->assertSoftDeleted('pulse_comments', ['id' => $comment->id]);
        $this->assertDatabaseHas('pulse_moderation_logs', [
            'target_type' => PulseComment::class,
            'target_id' => $comment->id,
            'action' => 'remove',
        ]);

        $elsewhere = $this->postIn(null);
        $foreignComment = $this->commentOn($elsewhere);

        Livewire::actingAs($moderator)->test(PostShow::class, ['post' => $elsewhere])
            ->set('removeReason', 'Not my community.')
            ->call('removeCommentAsModerator', $foreignComment->id)
            ->assertForbidden();

        $this->assertNull($foreignComment->fresh()->deleted_at);
        $this->assertSame(1, PulseModerationLog::count());
    }

    public function test_removal_by_a_moderator_requires_a_reason(): void
    {
        $post = $this->postIn(null);

        Livewire::actingAs($this->platformModerator())->test(PostShow::class, ['post' => $post])
            ->set('removeReason', '   ')
            ->call('removeAsModerator')
            ->assertHasErrors('removeReason');

        $this->assertSame('published', $post->fresh()->status);
    }
}
