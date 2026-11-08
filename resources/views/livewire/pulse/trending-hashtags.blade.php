<section class="pk-card" aria-labelledby="trending-title">
    <h2 id="trending-title" class="lg-h">Tags this week</h2>

    @if($this->trending->isEmpty())
        <p class="pk-muted">No tags this week. Put #something in a post to start one.</p>
    @else
        <ul class="lg-trend">
            @foreach($this->trending as $hashtag)
                <li wire:key="trending-{{ $hashtag->id }}">
                    <button type="button" wire:click="filterByHashtag('{{ $hashtag->name }}')" class="lg-trend__btn">
                        <span>#{{ $hashtag->name }}</span>
                        <span class="lg-act__n" aria-label="{{ $hashtag->recent_posts_count }} recent posts">{{ $hashtag->recent_posts_count }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</section>
