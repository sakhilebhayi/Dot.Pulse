<div>
    <div class="section-head">
        <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;">Community Feed</h3>
        <div class="flex items-center gap-3" style="flex-wrap:wrap;">
            <div style="display:flex;gap:4px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);border-radius:8px;padding:3px;">
                <button wire:click="$set('followingOnly', false)" class="tap" style="padding:5px 12px;border-radius:6px;border:none;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;background:{{ ! $followingOnly ? 'rgba(241,198,46,0.12)' : 'transparent' }};color:{{ ! $followingOnly ? '#f1c62e' : '#71717a' }};">For You</button>
                <button wire:click="$set('followingOnly', true)" class="tap" style="padding:5px 12px;border-radius:6px;border:none;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;background:{{ $followingOnly ? 'rgba(241,198,46,0.12)' : 'transparent' }};color:{{ $followingOnly ? '#f1c62e' : '#71717a' }};">Following</button>
            </div>
            <select wire:model.live="filterType" class="dot-input tap" aria-label="Filter by post type" style="font-size:11px;padding:5px 10px;width:auto;">
                <option value="">All types</option>
                @foreach(\App\Models\PulsePost::$types as $type)
                    <option value="{{ $type }}">{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if($hashtagFilter)
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:1rem;">
            <span class="dot-badge dot-badge-accent" style="font-family:'JetBrains Mono',monospace;">
                #{{ $hashtagFilter }}
            </span>
            <button wire:click="clearHashtagFilter" class="tap" style="background:none;border:none;color:#52525b;font-size:11px;cursor:pointer;">Clear</button>
        </div>
    @endif

    @if($this->posts->isEmpty())
        @if($followingOnly)
            <div class="dot-card" style="padding:1.5rem;">
                <p style="font-size:13px;color:#71717a;margin:0 0 1rem;text-align:center;">Follow people to see their posts here.</p>
                <livewire:pulse.suggested-users />
            </div>
        @else
            <div class="dot-card" style="padding:3rem 1.5rem;text-align:center;">
                <p style="font-size:13px;color:#71717a;margin:0;">No posts yet. Be the first to share something with the community.</p>
            </div>
        @endif
    @else
        <div style="display:flex;flex-direction:column;gap:0.9rem;">
            @foreach($this->posts as $post)
                <div class="dot-card" style="padding:1.25rem 1.5rem;">
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <a href="{{ route('users.show', $post->author) }}" class="dot-avatar" style="width:36px;height:36px;font-size:13px;">
                            {{ strtoupper(substr($post->author->name, 0, 1)) }}
                        </a>
                        <div style="flex:1;min-width:0;">
                            <div class="meta-row">
                                <a href="{{ route('users.show', $post->author) }}" style="font-size:13.5px;font-weight:600;color:#f4f4f5;text-decoration:none;">{{ $post->author->name }}</a>
                                <livewire:pulse.follow-button :user="$post->author" :compact="true" wire:key="follow-{{ $post->author->id }}-feed-{{ $post->id }}" />
                                <span class="dot-badge dot-badge-accent">
                                    {{ str_replace('_', ' ', ucfirst($post->type)) }}
                                </span>
                                @if($post->community)
                                    <a href="{{ route('communities.show', $post->community) }}" style="font-size:11px;color:#52525b;text-decoration:none;">in {{ $post->community->name }}</a>
                                @endif
                                <span style="font-size:11px;color:#3f3f46;margin-left:auto;white-space:nowrap;">{{ $post->created_at->diffForHumans() }}</span>
                            </div>

                            @if($post->title)
                                <a href="{{ route('posts.show', $post) }}" style="display:block;text-decoration:none;">
                                    <h4 style="font-size:14.5px;font-weight:600;color:#f4f4f5;margin:2px 0 4px;">{{ $post->title }}</h4>
                                </a>
                            @endif

                            <p style="font-size:13px;color:#a1a1aa;line-height:1.6;margin:0;" class="line-clamp-3">{{ $post->body }}</p>

                            @if($post->enrichment?->tags)
                                <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:8px;">
                                    @foreach(array_slice($post->enrichment->tags, 0, 4) as $tag)
                                        <span class="dot-badge dot-badge-neutral" style="font-family:'JetBrains Mono',monospace;">#{{ $tag }}</span>
                                    @endforeach
                                </div>
                            @endif

                            <div class="action-row" style="margin-top:12px;">
                                <button wire:click="react({{ $post->id }})" class="tap" aria-label="React to this post" style="display:flex;align-items:center;gap:5px;background:none;border:none;font-size:12px;color:#71717a;cursor:pointer;font-family:'Inter',sans-serif;">
                                    <span>👍</span>
                                    <span>{{ $post->reactions_count }}</span>
                                </button>
                                <a href="{{ route('posts.show', $post) }}" class="tap" aria-label="Open comments" style="display:flex;align-items:center;gap:5px;font-size:12px;color:#71717a;text-decoration:none;">
                                    <span>💬</span>
                                    <span>{{ $post->comments_count }}</span>
                                </a>
                                @if($post->enrichment)
                                    <span class="dot-badge {{ $post->enrichment->sentiment === 'positive' ? 'dot-badge-success' : ($post->enrichment->sentiment === 'negative' ? 'dot-badge-danger' : 'dot-badge-neutral') }}" style="margin-left:auto;">
                                        {{ ucfirst($post->enrichment->sentiment ?? 'neutral') }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
