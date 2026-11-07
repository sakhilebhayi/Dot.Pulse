<div class="lg-feed">
    <div class="lg-toolbar">
        <div class="lg-seg" role="group" aria-label="Whose posts">
            <button type="button" class="pk-btn pk-btn--sm {{ ! $followingOnly ? 'pk-btn--primary' : 'pk-btn--quiet' }}" wire:click="$set('followingOnly', false)" aria-pressed="{{ ! $followingOnly ? 'true' : 'false' }}">For you</button>
            <button type="button" class="pk-btn pk-btn--sm {{ $followingOnly ? 'pk-btn--primary' : 'pk-btn--quiet' }}" wire:click="$set('followingOnly', true)" aria-pressed="{{ $followingOnly ? 'true' : 'false' }}">Following</button>
        </div>
        <div class="lg-toolbar__type">
            <label for="feed-type" class="sr-only">Filter by post type</label>
            <select id="feed-type" wire:model.live="filterType" class="pk-control" aria-label="Filter by post type">
                <option value="">All types</option>
                @foreach(\App\Models\PulsePost::$types as $type)
                    <option value="{{ $type }}">{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if($hashtagFilter)
        <p class="lg-filter">
            <x-pulse.pill tone="signal">#{{ $hashtagFilter }}</x-pulse.pill>
            <button type="button" wire:click="clearHashtagFilter" class="lg-link">Clear the tag</button>
        </p>
    @endif

    @if($this->posts->isEmpty())
        @if($followingOnly)
            <div class="pk-card pk-stack">
                <p class="pk-muted">Follow people to see their posts here.</p>
                <livewire:pulse.suggested-users />
            </div>
        @else
            <x-pulse.empty title="No posts yet. Be the first to share something with the community." text="Say what you know, ask what you need. Posts that fit show up here." />
        @endif
    @else
        <div class="pk-stack">
            @foreach($this->posts as $post)
                <article class="pk-card lg-post" wire:key="post-{{ $post->id }}">
                    <header class="lg-post__head">
                        <a href="{{ route('users.show', $post->author) }}" class="lg-avatar" aria-label="{{ $post->author->name }}"><x-pulse.avatar :name="$post->author->name" /></a>
                        <div class="lg-post__meta">
                            <a href="{{ route('users.show', $post->author) }}" class="lg-post__name">{{ $post->author->name }}</a>
                            <livewire:pulse.follow-button :user="$post->author" :compact="true" wire:key="follow-{{ $post->author->id }}-feed-{{ $post->id }}" />
                            <x-pulse.kind-word :kind="str_replace('_', ' ', ucfirst($post->type))" />
                            @if($post->community)
                                <a href="{{ route('communities.show', $post->community) }}" class="lg-post__where">in {{ $post->community->name }}</a>
                            @endif
                        </div>
                        <time class="lg-post__time" datetime="{{ $post->created_at->toIso8601String() }}">{{ $post->created_at->diffForHumans() }}</time>
                    </header>

                    @if($post->title)
                        <h3 class="lg-post__title"><a href="{{ route('posts.show', $post) }}">{{ $post->title }}</a></h3>
                    @endif

                    <p class="lg-post__text lg-clamp">{{ $post->body }}</p>

                    @if($post->enrichment?->tags)
                        <ul class="lg-tags" aria-label="Tags">
                            @foreach(array_slice($post->enrichment->tags, 0, 4) as $tag)
                                <li>#{{ $tag }}</li>
                            @endforeach
                        </ul>
                    @endif

                    <footer class="lg-acts">
                        <button type="button" wire:click="react({{ $post->id }})" class="lg-act" aria-label="React to this post">
                            <span>Helpful</span><span class="lg-act__n">{{ $post->reactions_count }}</span>
                        </button>
                        <a href="{{ route('posts.show', $post) }}" class="lg-act" aria-label="Open comments">
                            <span>Comments</span><span class="lg-act__n">{{ $post->comments_count }}</span>
                        </a>
                        @if($post->enrichment)
                            <x-pulse.pill class="lg-acts__end" :tone="$post->enrichment->sentiment === 'positive' ? 'good' : ($post->enrichment->sentiment === 'negative' ? 'bad' : 'neutral')">{{ ucfirst($post->enrichment->sentiment ?? 'neutral') }}</x-pulse.pill>
                        @endif
                    </footer>
                </article>
            @endforeach
        </div>
    @endif
</div>
