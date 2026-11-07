<div class="pk-stack">
    @if(! $post->isPublished())
        @php
            [$headline, $detail] = match ($post->status) {
                'pending' => ['This post is being checked.', 'It is only visible to you until the automatic check finishes, usually within seconds.'],
                'flagged' => ['This post is waiting for a moderator.', 'It is only visible to you and the moderators until a person approves it.'],
                'removed' => ['This post was removed by a moderator.', 'It is only visible to you and the moderators.'],
                default => ['This post is not live.', 'It is only visible to you.'],
            };
        @endphp
        <div class="lg-callout" role="status">
            <p class="lg-callout__title">{{ $headline }}</p>
            <p class="pk-muted">{{ $detail }}</p>
        </div>
    @endif

    <article class="pk-card lg-post">
        <header class="lg-post__head">
            @php $linkable = \App\Models\PulseProfile::isLinkable($post->author, auth()->user()); @endphp
            @if($linkable)<a href="{{ route('users.show', $post->author) }}" class="lg-avatar" aria-label="{{ $post->author->name }}"><x-pulse.avatar :name="$post->author->name" /></a>@else<span class="lg-avatar"><x-pulse.avatar :name="$post->author->name" /></span>@endif
            <div class="lg-post__meta">
                @if($linkable)<a href="{{ route('users.show', $post->author) }}" class="lg-post__name">{{ $post->author->name }}</a>@else<span class="lg-post__name">{{ $post->author->name }}</span>@endif
                <livewire:pulse.follow-button :user="$post->author" :compact="true" wire:key="follow-{{ $post->author->id }}-postshow" />
                <x-pulse.kind-word :kind="str_replace('_', ' ', ucfirst($post->type))" />
                @if($post->community)
                    <a href="{{ route('communities.show', $post->community) }}" class="lg-post__where">in {{ $post->community->name }}</a>
                @endif
            </div>
            <time class="lg-post__time" datetime="{{ $post->created_at->toIso8601String() }}">{{ $post->created_at->diffForHumans() }}</time>
        </header>

        @if($editingPost)
            <form wire:submit="saveEdit" class="pk-stack">
                <x-pulse.field id="edit-title" name="editTitle" label="Title" aria-label="Title" optional wire:model="editTitle" />
                <x-pulse.field as="textarea" id="edit-body" name="editBody" label="Edit body" aria-label="Editbody" rows="5" wire:model="editBody" />
                <div class="pk-cluster">
                    <x-pulse.button type="submit" size="sm">Save</x-pulse.button>
                    <x-pulse.button variant="quiet" size="sm" wire:click="cancelEdit">Cancel</x-pulse.button>
                </div>
            </form>
        @else
            @if($post->title)<h2 class="lg-post__title lg-post__title--lg">{{ $post->title }}</h2>@endif
            <p class="lg-post__text">{{ $post->body }}</p>
            @if($post->edited_at)<p class="pk-muted lg-small">Edited {{ $post->edited_at->diffForHumans() }}</p>@endif
        @endif

        @if($post->enrichment?->tags)
            <ul class="lg-tags" aria-label="Tags">
                @foreach($post->enrichment->tags as $tag)<li>#{{ $tag }}</li>@endforeach
            </ul>
        @endif

        <footer class="lg-acts">
            <span class="lg-act lg-act--static"><span>Helpful</span><span class="lg-act__n">{{ $post->reactions_count }}</span></span>
            <span class="lg-act lg-act--static"><span>Comments</span><span class="lg-act__n">{{ $post->comments_count }}</span></span>
            @can('update', $post)
                @unless($editingPost)<button type="button" wire:click="startEditPost" class="lg-link">Edit</button>@endunless
            @endcan
            @can('delete', $post)
                @if($post->user_id === auth()->id())
                    <button type="button" wire:click="deletePost" wire:confirm="Delete this post? This also removes its comments from view." class="lg-link">Delete</button>
                @endif
            @endcan
            @can('report', $post)
                <livewire:pulse.report-button target-type="post" :target-id="$post->id" wire:key="report-post-{{ $post->id }}" />
            @endcan
            @can('remove', $post)
                @if($post->status !== 'removed')
                    <button type="button" wire:click="promptRemove('post')" class="lg-link lg-link--bad">Remove as moderator</button>
                @endif
            @endcan
        </footer>

        @if($removingTarget === 'post')
            <form wire:submit="removeAsModerator" class="lg-inline">
                <x-pulse.field id="remove-reason" name="removeReason" label="Reason for removing" aria-label="Reason for removing" hint="Kept in the moderation log." wire:model="removeReason" />
                <div class="pk-cluster">
                    <x-pulse.button type="submit" variant="danger" size="sm">Remove</x-pulse.button>
                    <x-pulse.button variant="quiet" size="sm" wire:click="cancelRemove">Cancel</x-pulse.button>
                </div>
            </form>
        @endif
    </article>

    <section class="pk-card lg-comments" aria-labelledby="comments-title">
        <h2 id="comments-title" class="lg-h">{{ $post->comments_count }} {{ Str::plural('comment', $post->comments_count) }}</h2>

        @can('comment', $post)
            <form wire:submit="postComment" class="pk-stack">
                <x-pulse.field as="textarea" id="new-comment" name="newCommentBody" label="Write a comment" aria-label="Write a comment" rows="3" placeholder="Write a comment" wire:model="newCommentBody" />
                <div><x-pulse.button type="submit" size="sm">Post comment</x-pulse.button></div>
            </form>
        @else
            <p class="pk-muted">Comments open once this post is live.</p>
        @endcan

        @if($this->comments->isEmpty())
            <p class="pk-muted lg-none">No comments yet. Start the conversation.</p>
        @else
            <div class="lg-thread">
                @foreach($this->comments as $comment)
                    <div wire:key="comment-{{ $comment->id }}">
                        @include('livewire.pulse.partials.comment', ['comment' => $comment, 'isReply' => false])

                        @if($comment->replies->isNotEmpty())
                            <div class="lg-replies">
                                @foreach($comment->replies as $reply)
                                    @include('livewire.pulse.partials.comment', ['comment' => $reply, 'isReply' => true])
                                @endforeach
                            </div>
                        @endif

                        @if($replyingToId === $comment->id)
                            <form wire:submit="postReply({{ $comment->id }})" class="lg-replies pk-stack">
                                <x-pulse.field as="textarea" :id="'reply-'.$comment->id" name="replyBody" label="Write a reply" aria-label="Write a reply" rows="2" placeholder="Write a reply" wire:model="replyBody" />
                                <div class="pk-cluster">
                                    <x-pulse.button type="submit" size="sm">Reply</x-pulse.button>
                                    <x-pulse.button variant="quiet" size="sm" wire:click="cancelReply">Cancel</x-pulse.button>
                                </div>
                            </form>
                        @else
                            @can('comment', $post)
                                <button type="button" wire:click="startReply({{ $comment->id }})" class="lg-link lg-link--reply">Reply</button>
                            @endcan
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
