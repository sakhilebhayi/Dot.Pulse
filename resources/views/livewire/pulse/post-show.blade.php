<div style="display:flex;flex-direction:column;gap:1rem;">
    @if(! $post->isPublished())
        @php
            [$headline, $detail] = match ($post->status) {
                'pending' => ['This post is being checked.', 'It is only visible to you until the automatic check finishes, usually within seconds.'],
                'flagged' => ['This post is waiting for a moderator.', 'It is only visible to you and the moderators until a person approves it.'],
                'removed' => ['This post was removed by a moderator.', 'It is only visible to you and the moderators.'],
                default => ['This post is not live.', 'It is only visible to you.'],
            };
        @endphp
        <div class="dot-card" style="padding:0.9rem 1.25rem;" role="status">
            <p style="font-size:13px;font-weight:600;color:#f1c62e;margin:0;">{{ $headline }}</p>
            <p style="font-size:12px;color:#a1a1aa;margin:3px 0 0;">{{ $detail }}</p>
        </div>
    @endif

    <div class="dot-card" style="padding:1.25rem 1.5rem;">
        <div style="display:flex;align-items:flex-start;gap:12px;">
            <a href="{{ route('users.show', $post->author) }}" class="dot-avatar" style="width:36px;height:36px;font-size:13px;">
                {{ strtoupper(substr($post->author->name, 0, 1)) }}
            </a>
            <div style="flex:1;min-width:0;">
                <div class="meta-row">
                    <a href="{{ route('users.show', $post->author) }}" style="font-size:13.5px;font-weight:600;color:#f4f4f5;text-decoration:none;">{{ $post->author->name }}</a>
                    <livewire:pulse.follow-button :user="$post->author" :compact="true" wire:key="follow-{{ $post->author->id }}-postshow" />
                    <span class="dot-badge dot-badge-accent">
                        {{ str_replace('_', ' ', ucfirst($post->type)) }}
                    </span>
                    @if($post->community)
                        <a href="{{ route('communities.show', $post->community) }}" style="font-size:11px;color:#71717a;text-decoration:none;">in {{ $post->community->name }}</a>
                    @endif
                    <span style="font-size:11px;color:#3f3f46;margin-left:auto;white-space:nowrap;">{{ $post->created_at->diffForHumans() }}</span>
                </div>

                @if($editingPost)
                    <form wire:submit="saveEdit" style="margin-top:8px;display:flex;flex-direction:column;gap:8px;">
                        <input aria-label="Title" wire:model="editTitle" type="text" placeholder="Title (optional)" class="dot-input" />
                        @error('editTitle')<p style="color:#f87171;font-size:11px;margin:0;">{{ $message }}</p>@enderror
                        <textarea aria-label="Editbody" wire:model="editBody" rows="5" class="dot-input"></textarea>
                        @error('editBody')<p style="color:#f87171;font-size:11px;margin:0;">{{ $message }}</p>@enderror
                        <div style="display:flex;gap:10px;">
                            <button type="submit" class="dot-btn dot-btn-primary tap" style="padding:5px 12px;font-size:12px;">Save</button>
                            <button type="button" wire:click="cancelEdit" class="tap" style="background:none;border:none;font-size:12px;color:#71717a;cursor:pointer;font-family:'Inter',sans-serif;">Cancel</button>
                        </div>
                    </form>
                @else
                    @if($post->title)
                        <h4 style="font-family:'Syne',sans-serif;font-size:17px;font-weight:700;color:#f4f4f5;margin:6px 0 6px;">{{ $post->title }}</h4>
                    @endif

                    <p style="font-size:13.5px;color:#a1a1aa;line-height:1.65;margin:0;white-space:pre-line;">{{ $post->body }}</p>
                    @if($post->edited_at)
                        <p style="font-size:11px;color:#52525b;margin:6px 0 0;">Edited {{ $post->edited_at->diffForHumans() }}</p>
                    @endif
                @endif

                @if($post->enrichment?->tags)
                    <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:10px;">
                        @foreach($post->enrichment->tags as $tag)
                            <span class="dot-badge dot-badge-neutral" style="font-family:'JetBrains Mono',monospace;">#{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif

                <div class="action-row" style="margin-top:14px;">
                    <span style="display:flex;align-items:center;gap:5px;font-size:12px;color:#71717a;">
                        <span>👍</span>
                        <span>{{ $post->reactions_count }}</span>
                    </span>
                    <span style="display:flex;align-items:center;gap:5px;font-size:12px;color:#71717a;">
                        <span>💬</span>
                        <span>{{ $post->comments_count }}</span>
                    </span>
                    @can('update', $post)
                        @unless($editingPost)
                            <button wire:click="startEditPost" class="tap" style="background:none;border:none;font-size:11.5px;color:#71717a;cursor:pointer;font-family:'Inter',sans-serif;">Edit</button>
                        @endunless
                    @endcan
                    @can('delete', $post)
                        @if($post->user_id === auth()->id())
                            <button wire:click="deletePost" wire:confirm="Delete this post? This also removes its comments from view." class="tap" style="background:none;border:none;font-size:11.5px;color:#71717a;cursor:pointer;font-family:'Inter',sans-serif;">Delete</button>
                        @endif
                    @endcan
                    @can('report', $post)
                        <livewire:pulse.report-button target-type="post" :target-id="$post->id" wire:key="report-post-{{ $post->id }}" />
                    @endcan
                    @can('remove', $post)
                        @if($post->status !== 'removed')
                            <button wire:click="promptRemove('post')" class="tap" style="background:none;border:none;font-size:11.5px;color:#f87171;cursor:pointer;font-family:'Inter',sans-serif;">Remove as moderator</button>
                        @endif
                    @endcan
                </div>

                @if($removingTarget === 'post')
                    <form wire:submit="removeAsModerator" class="inline-form-row" style="margin-top:10px;">
                        <input type="text" wire:model="removeReason" placeholder="Reason for removing (kept in the moderation log)" class="dot-input" aria-label="Reason for removing" />
                        <button type="submit" class="dot-btn tap" style="background:#e24b4a;color:#fff;flex-shrink:0;">Remove</button>
                        <button type="button" wire:click="cancelRemove" class="dot-btn dot-btn-ghost tap" style="flex-shrink:0;">Cancel</button>
                    </form>
                    @error('removeReason')<p style="color:#f87171;font-size:11px;margin:4px 0 0;">{{ $message }}</p>@enderror
                @endif
            </div>
        </div>
    </div>

    <div class="dot-card" style="padding:1.25rem 1.5rem;">
        <h3 style="font-family:'Syne',sans-serif;font-size:13.5px;font-weight:700;color:#f4f4f5;margin:0 0 0.9rem;">{{ $post->comments_count }} {{ Str::plural('comment', $post->comments_count) }}</h3>

        @can('comment', $post)
            <form wire:submit="postComment" style="margin-bottom:1.5rem;">
                <textarea aria-label="Write a comment" wire:model="newCommentBody" rows="3" placeholder="Write a comment…" class="dot-input"></textarea>
                @error('newCommentBody')<p style="color:#f87171;font-size:11px;margin:4px 0 0;">{{ $message }}</p>@enderror
                <button type="submit" class="dot-btn dot-btn-primary tap" style="margin-top:8px;">
                    Post comment
                </button>
            </form>
        @else
            <p style="font-size:12.5px;color:#71717a;margin:0 0 1.5rem;">Comments open once this post is live.</p>
        @endcan

        @if($this->comments->isEmpty())
            <p style="font-size:13px;color:#52525b;text-align:center;padding:1.5rem 0;margin:0;">No comments yet. Start the conversation.</p>
        @else
            <div style="display:flex;flex-direction:column;gap:1.25rem;">
                @foreach($this->comments as $comment)
                    <div>
                        @include('livewire.pulse.partials.comment', ['comment' => $comment, 'isReply' => false])

                        @if($comment->replies->isNotEmpty())
                            <div class="reply-thread">
                                @foreach($comment->replies as $reply)
                                    @include('livewire.pulse.partials.comment', ['comment' => $reply, 'isReply' => true])
                                @endforeach
                            </div>
                        @endif

                        @if($replyingToId === $comment->id)
                            <div class="reply-form">
                                <form wire:submit="postReply({{ $comment->id }})">
                                    <textarea aria-label="Write a reply" wire:model="replyBody" rows="2" placeholder="Write a reply…" class="dot-input"></textarea>
                                    @error('replyBody')<p style="color:#f87171;font-size:11px;margin:4px 0 0;">{{ $message }}</p>@enderror
                                    <div style="display:flex;gap:10px;margin-top:8px;">
                                        <button type="submit" class="dot-btn dot-btn-primary tap" style="padding:5px 12px;font-size:12px;">Reply</button>
                                        <button type="button" wire:click="cancelReply" class="tap" style="background:none;border:none;font-size:12px;color:#71717a;cursor:pointer;font-family:'Inter',sans-serif;">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        @else
                            @can('comment', $post)
                                <button wire:click="startReply({{ $comment->id }})" class="tap" style="margin-left:4px;margin-top:6px;background:none;border:none;font-size:11.5px;color:#71717a;cursor:pointer;font-family:'Inter',sans-serif;">Reply</button>
                            @endcan
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
