<?php

namespace App\Policies;

use App\Models\PulseComment;
use App\Models\User;

class PulseCommentPolicy
{
    private function postPolicy(): PulsePostPolicy
    {
        return app(PulsePostPolicy::class);
    }

    public function view(?User $user, PulseComment $comment): bool
    {
        return ! $comment->trashed() && $this->postPolicy()->view($user, $comment->post);
    }

    public function react(?User $user, PulseComment $comment): bool
    {
        return ! $comment->trashed() && $this->postPolicy()->react($user, $comment->post);
    }

    public function update(User $user, PulseComment $comment): bool
    {
        if ($comment->trashed() || $comment->user_id !== $user->id) {
            return false;
        }

        $window = (int) config('pulse.edit_window_minutes');

        return $window > 0
            && $comment->created_at->gt(now()->subMinutes($window))
            && $this->postPolicy()->view($user, $comment->post);
    }

    public function delete(User $user, PulseComment $comment): bool
    {
        if ($comment->trashed()) {
            return false;
        }

        return $comment->user_id === $user->id
            || $this->postPolicy()->moderate($user, $comment->post);
    }

    /** Moderator removal of someone else's comment (logged). */
    public function remove(User $user, PulseComment $comment): bool
    {
        return ! $comment->trashed()
            && $comment->user_id !== $user->id
            && $this->postPolicy()->moderate($user, $comment->post);
    }

    public function report(User $user, PulseComment $comment): bool
    {
        return ! $comment->trashed()
            && $comment->user_id !== $user->id
            && $this->postPolicy()->view($user, $comment->post)
            && $comment->post->isPublished();
    }
}
