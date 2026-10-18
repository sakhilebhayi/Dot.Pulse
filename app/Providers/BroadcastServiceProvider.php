<?php

namespace App\Providers;

use App\Models\PulsePost;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Broadcast::routes() is already registered by bootstrap/app.php's
        // withRouting(channels: ...), which also requires routes/channels.php.
        // Only the private channel authorization callbacks live here.
        $this->requireChannels();
    }

    /**
     * Authenticate access to private channels.
     *
     * {postId} is typed as plain (non-int) deliberately: a malformed value
     * would otherwise throw an uncaught TypeError while PHP tries to
     * coerce it to an `int` parameter -- an unauthorized 500 instead of a
     * clean, fail-closed 403. See docs/DOT_REALTIME_STANDARD.md
     * (Dot.Mines) §6.
     */
    protected function requireChannels(): void
    {
        /**
         * CommentPosted broadcasts here (see App\Events\CommentPosted).
         * Matches PostShow::mount()'s own view boundary exactly -- a
         * held/removed post's comment stream is only visible to its own
         * author, the same rule the HTTP page already enforces. A private
         * channel per post rather than one global channel, since a
         * held post's comments must never reach anyone but its author.
         */
        Broadcast::channel('post.{postId}', function (User $user, $postId) {
            if (! is_string($postId) || ! ctype_digit($postId)) {
                return false;
            }

            $post = PulsePost::find((int) $postId);

            if (! $post) {
                return false;
            }

            // The same rule the page itself enforces (PulsePostPolicy::view):
            // live posts to anyone who can see the community, held or
            // removed posts to their author and responsible moderators.
            return Gate::forUser($user)->allows('view', $post);
        });

        /**
         * App\Notifications\NewFollower broadcasts here (via
         * User::receivesBroadcastNotificationsOn()). Same fail-closed,
         * non-int-typed-parameter shape as post.{postId} above -- only
         * the user themselves may ever subscribe to their own
         * notification stream.
         */
        Broadcast::channel('user.{userId}', function (User $user, $userId) {
            if (! is_string($userId) || ! ctype_digit($userId)) {
                return false;
            }

            return (string) $user->id === $userId;
        });
    }
}
