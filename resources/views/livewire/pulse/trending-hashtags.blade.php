<div class="dot-card" style="padding:1.25rem 1.5rem;">
    <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0 0 0.9rem;">Trending</h3>

    @if($this->trending->isEmpty())
        <p style="font-size:0.78rem;color:#52525b;margin:0;">Nothing trending yet. Tag a post with #something to get it started.</p>
    @else
        <div style="display:flex;flex-direction:column;gap:0.4rem;">
            @foreach($this->trending as $hashtag)
                <button wire:click="filterByHashtag('{{ $hashtag->name }}')" wire:key="trending-{{ $hashtag->id }}" class="tap"
                    style="display:flex;align-items:center;justify-content:space-between;background:none;border:none;padding:4px 2px;cursor:pointer;text-align:left;color:inherit;">
                    <span style="font-size:12px;color:#a1a1aa;font-family:'JetBrains Mono',monospace;">#{{ $hashtag->name }}</span>
                    <span style="font-size:11px;color:#52525b;">{{ $hashtag->recent_posts_count }}</span>
                </button>
            @endforeach
        </div>
    @endif
</div>
