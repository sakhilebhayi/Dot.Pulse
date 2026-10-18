@php
    $author = $comment->author;
@endphp
<div style="display:flex;align-items:flex-start;gap:10px;">
    <a href="{{ route('users.show', $author) }}" class="dot-avatar" style="width:28px;height:28px;font-size:11px;">
        {{ strtoupper(substr($author->name, 0, 1)) }}
    </a>
    <div style="flex:1;min-width:0;">
        <div class="meta-row" style="margin-bottom:0;">
            <a href="{{ route('users.show', $author) }}" style="font-size:12.5px;font-weight:600;color:#f4f4f5;text-decoration:none;">{{ $author->name }}</a>
            <livewire:pulse.follow-button :user="$author" :compact="true" wire:key="follow-{{ $author->id }}-comment-{{ $comment->id }}" />
            @if($comment->is_solution)
                <span class="dot-badge dot-badge-success">✓ Solution</span>
            @endif
            <span style="font-size:11px;color:#3f3f46;">{{ $comment->created_at->diffForHumans() }}</span>
        </div>
        @if($editingCommentId === $comment->id)
            <form wire:submit="saveEdit" style="margin-top:6px;">
                <textarea wire:model="editBody" rows="3" class="dot-input"></textarea>
                @error('editBody')<p style="color:#f87171;font-size:11px;margin:4px 0 0;">{{ $message }}</p>@enderror
                <div style="display:flex;gap:10px;margin-top:8px;">
                    <button type="submit" class="dot-btn dot-btn-primary tap" style="padding:5px 12px;font-size:12px;">Save</button>
                    <button type="button" wire:click="cancelEdit" class="tap" style="background:none;border:none;font-size:12px;color:#71717a;cursor:pointer;font-family:'Inter',sans-serif;">Cancel</button>
                </div>
            </form>
        @else
            <p style="font-size:13px;color:#a1a1aa;line-height:1.55;margin:4px 0 0;">{{ $comment->body }}@if($comment->edited_at) <span style="font-size:11px;color:#52525b;">(edited)</span>@endif</p>
        @endif
        <div class="action-row" style="gap:2px 14px;margin-top:6px;">
            <button wire:click="react({{ $comment->id }})" class="tap" style="display:flex;align-items:center;gap:4px;background:none;border:none;font-size:11.5px;color:#71717a;cursor:pointer;font-family:'Inter',sans-serif;">
                <span>👍</span>
                <span>{{ $comment->reactions_count }}</span>
            </button>
            @if(! $isReply && ! $comment->is_solution)
                @can('markSolution', $post)
                    <button wire:click="markSolution({{ $comment->id }})" class="tap" style="background:none;border:none;font-size:11.5px;color:#71717a;cursor:pointer;font-family:'Inter',sans-serif;">Mark as solution</button>
                @endcan
            @endif
            @can('update', $comment)
                @if($editingCommentId !== $comment->id)
                    <button wire:click="startEditComment({{ $comment->id }})" class="tap" style="background:none;border:none;font-size:11.5px;color:#71717a;cursor:pointer;font-family:'Inter',sans-serif;">Edit</button>
                @endif
            @endcan
            @can('delete', $comment)
                @if($comment->user_id === auth()->id())
                    <button wire:click="deleteComment({{ $comment->id }})" wire:confirm="Delete this comment{{ $isReply ? '' : ' and its replies' }}?" class="tap" style="background:none;border:none;font-size:11.5px;color:#71717a;cursor:pointer;font-family:'Inter',sans-serif;">Delete</button>
                @endif
            @endcan
            @can('report', $comment)
                <livewire:pulse.report-button target-type="comment" :target-id="$comment->id" wire:key="report-comment-{{ $comment->id }}" />
            @endcan
            @can('remove', $comment)
                <button wire:click="promptRemove('comment:{{ $comment->id }}')" class="tap" style="background:none;border:none;font-size:11.5px;color:#f87171;cursor:pointer;font-family:'Inter',sans-serif;">Remove as moderator</button>
            @endcan
        </div>
        @if($removingTarget === 'comment:'.$comment->id)
            <form wire:submit="removeCommentAsModerator({{ $comment->id }})" class="inline-form-row" style="margin-top:8px;">
                <input type="text" wire:model="removeReason" placeholder="Reason for removing (kept in the moderation log)" class="dot-input" aria-label="Reason for removing" />
                <button type="submit" class="dot-btn tap" style="background:#e24b4a;color:#fff;flex-shrink:0;padding:5px 12px;font-size:12px;">Remove</button>
                <button type="button" wire:click="cancelRemove" class="dot-btn dot-btn-ghost tap" style="flex-shrink:0;padding:5px 12px;font-size:12px;">Cancel</button>
            </form>
            @error('removeReason')<p style="color:#f87171;font-size:11px;margin:4px 0 0;">{{ $message }}</p>@enderror
        @endif
    </div>
</div>
