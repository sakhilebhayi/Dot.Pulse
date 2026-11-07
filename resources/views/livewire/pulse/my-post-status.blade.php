<div @if($this->posts->contains('status', 'pending')) wire:poll.10s @endif>
    @if($this->posts->isNotEmpty())
        <section class="pk-card lg-status" aria-labelledby="mine-title">
            <h2 id="mine-title" class="lg-h">Your posts not in the feed</h2>
            <div class="lg-list">
                @foreach($this->posts as $post)
                    @php
                        [$label, $tone, $text] = match ($post->status) {
                            'pending' => ['Checking', 'neutral', 'An automatic check is running. It usually takes a few seconds.'],
                            'flagged' => ['Waiting for a moderator', 'warn', 'A person will look at this before it goes live. Nothing you need to do.'],
                            default => ['Removed', 'bad', $this->removalReasons[$post->id] ?? 'A moderator removed this post.'],
                        };
                    @endphp
                    <a href="{{ route('posts.show', $post) }}" class="pk-row lg-rowlink" wire:key="mine-{{ $post->id }}">
                        <span class="pk-row__main">
                            <span class="pk-row__title">{{ $post->title ?: \Illuminate\Support\Str::limit($post->body, 70) }}</span>
                            <span class="pk-row__meta">{{ $text }}</span>
                        </span>
                        <x-pulse.pill :tone="$tone">{{ $label }}</x-pulse.pill>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</div>
