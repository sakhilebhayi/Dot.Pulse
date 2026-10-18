<?php

namespace App\Events;

use App\Models\PulsePost;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells open feeds that a post just went live, so they refetch. It is a
 * nudge, not a copy of the post: the payload is only what that nudge needs,
 * and the feed itself is re-queried through the viewer's own visibility
 * rules.
 *
 * Channels (all private, i.e. authenticated through /broadcasting/auth):
 *  - a post with no community, or in a public one, goes to `pulse.feed`,
 *    which any signed-in user may join;
 *  - a post in a private community goes ONLY to `community.{id}`, which only
 *    members may join, and carries no community name.
 *
 * It used to go to a public channel named `pulse-feed` with the title and
 * community name of every post, including private-community posts, to
 * anyone who opened a websocket.
 *
 * ShouldDispatchAfterCommit: never announce a post inside a transaction that
 * could still roll back.
 */
class PostPublished implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public PulsePost $post) {}

    public function broadcastOn(): array
    {
        $community = $this->post->community;

        if ($community !== null && $community->isPrivate()) {
            return [new PrivateChannel("community.{$community->id}")];
        }

        return [new PrivateChannel('pulse.feed')];
    }

    public function broadcastAs(): string
    {
        return 'post.published';
    }

    public function broadcastWith(): array
    {
        $community = $this->post->community;

        return [
            'id' => $this->post->id,
            'type' => $this->post->type,
            'author_name' => $this->post->author->name,
            // Only ever a public community's name.
            'community_name' => $community?->isPublic() ? $community->name : null,
        ];
    }
}
