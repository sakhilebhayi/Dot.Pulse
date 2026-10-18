<?php

namespace App\Events;

use App\Models\PulseComment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Live comment updates on a post's own page (PostShow) -- fired from
 * PostShow::postComment()/postReply(). Private per-post channel: matches
 * PostShow::mount()'s own view boundary (App\Providers\BroadcastServiceProvider
 * enforces the identical rule for the channel), so a held/removed post's
 * comment stream is never broadcast to anyone but its own author.
 */
class CommentPosted implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public PulseComment $comment) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("post.{$this->comment->pulse_post_id}")];
    }

    public function broadcastAs(): string
    {
        return 'comment.posted';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->comment->id,
            'parent_id' => $this->comment->parent_id,
            'body' => $this->comment->body,
            'author_name' => $this->comment->author->name,
            'author_id' => $this->comment->user_id,
            'created_at' => $this->comment->created_at->toIso8601String(),
        ];
    }
}
