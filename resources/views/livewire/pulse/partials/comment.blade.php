@php
    $author = $comment->author;
    $linkable = \App\Models\PulseProfile::isLinkable($author, auth()->user());
@endphp
<div class="lg-comment">
    @if($linkable)<a href="{{ route('users.show', $author) }}" class="lg-avatar" aria-label="{{ $author->name }}"><x-pulse.avatar :name="$author->name" size="sm" /></a>@else<span class="lg-avatar"><x-pulse.avatar :name="$author->name" size="sm" /></span>@endif
    <div class="lg-comment__main">
        <div class="lg-post__meta">
            @if($linkable)<a href="{{ route('users.show', $author) }}" class="lg-post__name">{{ $author->name }}</a>@else<span class="lg-post__name">{{ $author->name }}</span>@endif
            <livewire:pulse.follow-button :user="$author" :compact="true" wire:key="follow-{{ $author->id }}-comment-{{ $comment->id }}" />
            @if($comment->is_solution)<x-pulse.pill tone="good">Solution</x-pulse.pill>@endif
            <time class="lg-post__time" datetime="{{ $comment->created_at->toIso8601String() }}">{{ $comment->created_at->diffForHumans() }}</time>
        </div>

        @if($editingCommentId === $comment->id)
            <form wire:submit="saveEdit" class="pk-stack">
                <x-pulse.field as="textarea" :id="'edit-comment-'.$comment->id" name="editBody" label="Edit comment" aria-label="Editbody" rows="3" wire:model="editBody" />
                <div class="pk-cluster">
                    <x-pulse.button type="submit" size="sm">Save</x-pulse.button>
                    <x-pulse.button variant="quiet" size="sm" wire:click="cancelEdit">Cancel</x-pulse.button>
                </div>
            </form>
        @else
            <p class="lg-post__text">{{ $comment->body }}@if($comment->edited_at) <span class="pk-muted lg-small">(edited)</span>@endif</p>
        @endif

        <div class="lg-acts">
            <button type="button" wire:click="react({{ $comment->id }})" class="lg-act" aria-label="React to this comment">
                <span>Helpful</span><span class="lg-act__n">{{ $comment->reactions_count }}</span>
            </button>
            @if(! $isReply && ! $comment->is_solution)
                @can('markSolution', $post)
                    <button type="button" wire:click="markSolution({{ $comment->id }})" class="lg-link">Mark as solution</button>
                @endcan
            @endif
            @can('update', $comment)
                @if($editingCommentId !== $comment->id)
                    <button type="button" wire:click="startEditComment({{ $comment->id }})" class="lg-link">Edit</button>
                @endif
            @endcan
            @can('delete', $comment)
                @if($comment->user_id === auth()->id())
                    <button type="button" wire:click="deleteComment({{ $comment->id }})" wire:confirm="Delete this comment{{ $isReply ? '' : ' and its replies' }}?" class="lg-link">Delete</button>
                @endif
            @endcan
            @can('report', $comment)
                <livewire:pulse.report-button target-type="comment" :target-id="$comment->id" wire:key="report-comment-{{ $comment->id }}" />
            @endcan
            @can('remove', $comment)
                <button type="button" wire:click="promptRemove('comment:{{ $comment->id }}')" class="lg-link lg-link--bad">Remove as moderator</button>
            @endcan
        </div>

        @if($removingTarget === 'comment:'.$comment->id)
            <form wire:submit="removeCommentAsModerator({{ $comment->id }})" class="lg-inline">
                <x-pulse.field :id="'remove-comment-'.$comment->id" name="removeReason" label="Reason for removing" aria-label="Reason for removing" hint="Kept in the moderation log." wire:model="removeReason" />
                <div class="pk-cluster">
                    <x-pulse.button type="submit" variant="danger" size="sm">Remove</x-pulse.button>
                    <x-pulse.button variant="quiet" size="sm" wire:click="cancelRemove">Cancel</x-pulse.button>
                </div>
            </form>
        @endif
    </div>
</div>
