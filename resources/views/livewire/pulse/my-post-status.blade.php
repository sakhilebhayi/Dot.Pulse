<div @if($this->posts->contains('status', 'pending')) wire:poll.10s @endif>
    @if($this->posts->isNotEmpty())
        <div class="dot-card" style="padding:1rem 1.25rem;margin-top:1.25rem;">
            <h3 style="font-family:'Syne',sans-serif;font-size:12.5px;font-weight:700;color:#f4f4f5;margin:0 0 0.6rem;">Your posts not in the feed</h3>
            <div style="display:flex;flex-direction:column;gap:2px;">
                @foreach($this->posts as $post)
                    @php
                        [$label, $class, $text] = match ($post->status) {
                            'pending' => ['Checking', 'dot-badge-neutral', 'An automatic check is running. It usually takes a few seconds.'],
                            'flagged' => ['Waiting for a moderator', 'dot-badge-accent', 'A person will look at this before it goes live. Nothing you need to do.'],
                            default => ['Removed', 'dot-badge-danger', $this->removalReasons[$post->id] ?? 'A moderator removed this post.'],
                        };
                    @endphp
                    <a href="{{ route('posts.show', $post) }}" class="dot-row" style="display:flex;align-items:center;gap:12px;padding:10px 14px;text-decoration:none;" wire:key="mine-{{ $post->id }}">
                        <div style="min-width:0;flex:1;">
                            <p style="font-size:13px;font-weight:600;color:#f4f4f5;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $post->title ?: \Illuminate\Support\Str::limit($post->body, 70) }}</p>
                            <p style="font-size:11.5px;color:#71717a;margin:2px 0 0;">{{ $text }}</p>
                        </div>
                        <span class="dot-badge {{ $class }}" style="flex-shrink:0;">{{ $label }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</div>
