<?php

namespace App\Livewire\Pulse;

use App\Events\CommentPosted;
use App\Jobs\ModeratePost;
use App\Models\PulseComment;
use App\Models\PulsePost;
use App\Services\ModerationActions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A post's own page with its threaded comments.
 *
 * Every action re-checks the viewer's rights against a freshly loaded post:
 * Livewire rehydrates the model on each request, so a post that was held,
 * removed or moved out of the viewer's reach after the page opened stops
 * accepting actions immediately instead of trusting the first render.
 */
class PostShow extends Component
{
    public PulsePost $post;

    public string $newCommentBody = '';

    public ?int $replyingToId = null;

    public string $replyBody = '';

    /** True while the post's own edit form is open. */
    public bool $editingPost = false;

    public ?int $editingCommentId = null;

    public string $editTitle = '';

    public string $editBody = '';

    /** 'post' or 'comment:{id}' while a moderator is typing a removal reason. */
    public ?string $removingTarget = null;

    public string $removeReason = '';

    public function mount(PulsePost $post): void
    {
        // A post the viewer cannot see does not exist as far as they know:
        // held/removed posts are visible only to their author and the
        // moderators who answer for them, and private-community posts only
        // to members.
        abort_unless(Gate::allows('view', $post), 404);

        $this->post = $post;
    }

    private function authorizeView(): void
    {
        abort_unless(Gate::allows('view', $this->post), 404);
    }

    #[Computed]
    public function comments(): Collection
    {
        $comments = $this->post->comments()
            ->with(['author.pulseProfile', 'replies.author.pulseProfile'])
            ->oldest()
            ->get();

        // The policy walks comment -> post; hand it the post we already have.
        foreach ($comments as $comment) {
            $comment->setRelation('post', $this->post);
            foreach ($comment->replies as $reply) {
                $reply->setRelation('post', $this->post);
            }
        }

        return $comments;
    }

    /**
     * resources/js/pulse-realtime.js dispatches this on a live
     * App\Events\CommentPosted broadcast.
     */
    #[On('comment-posted')]
    public function refreshComments(): void
    {
        unset($this->comments);
    }

    public function postComment(): void
    {
        $this->authorizeView();
        Gate::authorize('comment', $this->post);
        $this->validate(['newCommentBody' => 'required|string|min:1|max:5000']);

        $comment = DB::transaction(function () {
            $comment = $this->post->comments()->create([
                'user_id' => auth()->id(),
                'body' => $this->newCommentBody,
            ]);
            PulsePost::whereKey($this->post->id)->increment('comments_count');

            return $comment;
        });

        $this->post->refresh();
        $this->broadcastSafely(fn () => CommentPosted::dispatch($comment));
        $this->reset('newCommentBody');
        unset($this->comments);
    }

    public function startReply(int $commentId): void
    {
        $this->replyingToId = $commentId;
        $this->replyBody = '';
    }

    public function cancelReply(): void
    {
        $this->replyingToId = null;
        $this->replyBody = '';
    }

    public function postReply(int $parentId): void
    {
        $this->authorizeView();
        Gate::authorize('comment', $this->post);
        $this->validate(['replyBody' => 'required|string|min:1|max:5000']);

        $parent = $this->findComment($parentId);

        $reply = DB::transaction(function () use ($parent) {
            $reply = $parent->replies()->create([
                'pulse_post_id' => $this->post->id,
                'user_id' => auth()->id(),
                'body' => $this->replyBody,
            ]);
            PulsePost::whereKey($this->post->id)->increment('comments_count');

            return $reply;
        });

        $this->post->refresh();
        $this->broadcastSafely(fn () => CommentPosted::dispatch($reply));
        $this->cancelReply();
        unset($this->comments);
    }

    /** A live (not deleted) comment that belongs to this post, or a 404. */
    private function findComment(int $commentId): PulseComment
    {
        $comment = PulseComment::where('pulse_post_id', $this->post->id)->find($commentId);
        abort_if($comment === null, 404);
        $comment->setRelation('post', $this->post);

        return $comment;
    }

    /**
     * ShouldBroadcast + ShouldDispatchAfterCommit: the row is already
     * durable. A broadcast failure (Reverb down) is a lost live update, not
     * a reason to 500 the request.
     */
    private function broadcastSafely(\Closure $dispatch): void
    {
        try {
            $dispatch();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Toggle-react on a comment (pulse_reactions is unique per user per
     * target). The post must itself be reactable: published and visible.
     */
    public function react(int $commentId, string $emoji = '👍'): void
    {
        $this->authorizeView();
        abort_unless(in_array($emoji, config('pulse.reaction_emojis'), true), 422, 'Unsupported reaction.');

        $comment = $this->findComment($commentId);
        Gate::authorize('react', $comment);

        $comment->toggleReaction(auth()->user(), $emoji);

        unset($this->comments);
    }

    /**
     * Only the post's own author marks a solution, and only on a live post.
     * At most one solution per post: marking a new one unmarks the previous.
     */
    public function markSolution(int $commentId): void
    {
        $this->authorizeView();
        Gate::authorize('markSolution', $this->post);

        $comment = $this->findComment($commentId);

        if ($comment->is_solution) {
            return;
        }

        DB::transaction(function () use ($comment) {
            PulseComment::where('pulse_post_id', $this->post->id)
                ->where('is_solution', true)
                ->update(['is_solution' => false]);

            $comment->update(['is_solution' => true]);
        });

        $profile = $comment->author->pulseProfile;
        if ($profile) {
            $profile->addPoints(10);
            $profile->increment('solutions_accepted');
        }

        unset($this->comments);
    }

    // -- Editing and deleting your own words ------------------------------

    public function startEditPost(): void
    {
        $this->authorizeView();
        Gate::authorize('update', $this->post);

        $this->editingPost = true;
        $this->editingCommentId = null;
        $this->editTitle = (string) $this->post->title;
        $this->editBody = $this->post->body;
    }

    public function startEditComment(int $commentId): void
    {
        $this->authorizeView();
        $comment = $this->findComment($commentId);
        Gate::authorize('update', $comment);

        $this->editingPost = false;
        $this->editingCommentId = $comment->id;
        $this->editBody = $comment->body;
    }

    public function cancelEdit(): void
    {
        $this->editingPost = false;
        $this->editingCommentId = null;
        $this->editTitle = '';
        $this->editBody = '';
    }

    public function saveEdit(): void
    {
        $this->authorizeView();

        if ($this->editingCommentId !== null) {
            $comment = $this->findComment($this->editingCommentId);
            Gate::authorize('update', $comment);
            $this->validate(['editBody' => 'required|string|min:1|max:5000']);

            $comment->update(['body' => $this->editBody, 'edited_at' => now()]);
        } elseif ($this->editingPost) {
            Gate::authorize('update', $this->post);
            $this->validate([
                'editTitle' => 'nullable|string|max:200',
                'editBody' => 'required|string|min:10|max:10000',
            ]);

            $title = $this->editTitle ?: null;

            if ($title !== $this->post->title || $this->editBody !== $this->post->body) {
                $attributes = ['title' => $title, 'body' => $this->editBody, 'edited_at' => now()];

                // New words on a live post get the same check a new post
                // gets, otherwise editing would be a way around moderation.
                // It is hidden again until the check decides.
                if ($this->post->isPublished()) {
                    $attributes['status'] = 'pending';
                }

                $this->post->update($attributes);
            }

            if ($this->post->status === 'pending') {
                try {
                    ModeratePost::dispatch($this->post)->afterCommit();
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        } else {
            return;
        }

        $this->cancelEdit();
        unset($this->comments);
    }

    public function deletePost(): void
    {
        $this->authorizeView();
        Gate::authorize('delete', $this->post);

        $this->post->delete();

        $this->redirectRoute('dashboard');
    }

    public function deleteComment(int $commentId): void
    {
        $this->authorizeView();
        $comment = $this->findComment($commentId);
        Gate::authorize('delete', $comment);

        $comment->remove();

        $this->post->refresh();
        unset($this->comments);
    }

    // -- Moderator removal (kept, hidden, logged) -------------------------

    public function promptRemove(string $target): void
    {
        $this->authorizeView();
        $this->removingTarget = $target;
        $this->removeReason = '';
    }

    public function cancelRemove(): void
    {
        $this->removingTarget = null;
        $this->removeReason = '';
    }

    public function removeAsModerator(): void
    {
        $this->authorizeView();
        Gate::authorize('remove', $this->post);
        $this->validate(['removeReason' => ['required', 'string', 'regex:/\S/', 'max:1000']]);

        app(ModerationActions::class)->removePost($this->post, auth()->user(), $this->removeReason);

        $this->cancelRemove();
        $this->post->refresh();
    }

    public function removeCommentAsModerator(int $commentId): void
    {
        $this->authorizeView();
        $comment = $this->findComment($commentId);
        Gate::authorize('remove', $comment);
        $this->validate(['removeReason' => ['required', 'string', 'regex:/\S/', 'max:1000']]);

        app(ModerationActions::class)->removeComment($comment, auth()->user(), $this->removeReason);

        $this->cancelRemove();
        $this->post->refresh();
        unset($this->comments);
    }

    public function render(): View
    {
        return view('livewire.pulse.post-show');
    }
}
