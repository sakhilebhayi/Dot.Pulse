<?php

namespace App\Policies;

use App\Models\PulsePost;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PulsePostPolicy
{
    use HandlesAuthorization;

    /**
     * Live posts: anyone who can see the community (or any viewer, when
     * there is none). Held, pending or removed posts: their author, and
     * the moderators who answer for them.
     */
    public function view(?User $user, PulsePost $post): bool
    {
        if ($post->trashed()) {
            return false;
        }

        $communityVisible = $post->community_id === null
            || ($post->community?->isVisibleTo($user) ?? false);

        if ($post->isPublished()) {
            return $communityVisible;
        }

        if ($user === null) {
            return false;
        }

        return ($post->user_id === $user->id && $communityVisible)
            || $this->moderate($user, $post);
    }

    public function react(?User $user, PulsePost $post): bool
    {
        return $user !== null && $post->isPublished() && $this->view($user, $post);
    }

    public function comment(?User $user, PulsePost $post): bool
    {
        return $this->react($user, $post);
    }

    public function markSolution(User $user, PulsePost $post): bool
    {
        return $post->user_id === $user->id && $post->isPublished() && $this->view($user, $post);
    }

    /** Authors only, inside the configured window, never once removed. */
    public function update(User $user, PulsePost $post): bool
    {
        if ($post->trashed() || $post->status === 'removed' || $post->user_id !== $user->id) {
            return false;
        }

        $window = (int) config('pulse.edit_window_minutes');

        return $window > 0 && $post->created_at->gt(now()->subMinutes($window));
    }

    /** An author may always delete their own post; staff only within their scope. */
    public function delete(User $user, PulsePost $post): bool
    {
        if ($post->trashed()) {
            return false;
        }

        return $post->user_id === $user->id || $this->moderate($user, $post);
    }

    /** A moderator removing someone else's post (kept, hidden, and logged). */
    public function remove(User $user, PulsePost $post): bool
    {
        return ! $post->trashed()
            && $post->user_id !== $user->id
            && $this->moderate($user, $post);
    }

    public function report(User $user, PulsePost $post): bool
    {
        return $post->user_id !== $user->id
            && $post->isPublished()
            && $this->view($user, $post);
    }

    /**
     * Class level (authorize('moderate', PulsePost::class)): may this user
     * open the moderation queue at all. Instance level: may they act on
     * this post. Platform moderators act everywhere; a community's own
     * moderators act on that community's posts.
     */
    public function moderate(User $user, string|PulsePost|null $post = null): bool
    {
        if ($user->isPlatformModerator()) {
            return true;
        }

        if (! $post instanceof PulsePost) {
            return $user->managedCommunityIds() !== [];
        }

        return $post->community_id !== null
            && ($post->community?->isManager($user) ?? false);
    }
}
