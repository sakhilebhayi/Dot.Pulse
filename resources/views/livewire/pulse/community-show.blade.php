<div class="pk-stack">
    <section class="pk-card lg-chead">
        <div class="lg-chead__main">
            <div class="lg-community__name">
                <h1 class="lg-chead__title">{{ $community->name }}</h1>
                @if($community->industry)<x-pulse.pill tone="signal">{{ $community->industry }}</x-pulse.pill>@endif
                @if($community->isPrivate())<x-pulse.pill icon="lock" title="Only members can see what is posted here">Private</x-pulse.pill>@endif
            </div>
            @if($community->description)<p class="lg-post__text">{{ $community->description }}</p>@endif
            <p class="pk-muted lg-small">{{ number_format($community->members_count) }} {{ \Illuminate\Support\Str::plural('member', $community->members_count) }}</p>
        </div>

        @auth
            @if($this->isMember)
                <x-pulse.button variant="quiet" wire:click="leave">Leave</x-pulse.button>
            @else
                <x-pulse.button wire:click="join">Join</x-pulse.button>
            @endif
        @endauth
    </section>

    @if($this->canManage && $this->pendingRequests->isNotEmpty())
        <section class="pk-card" aria-labelledby="asking-title">
            <h2 id="asking-title" class="lg-h">Asking to join</h2>
            <p class="pk-muted lg-small">{{ $this->pendingRequests->count() }} waiting for a manager of this community.</p>
            <div class="lg-list">
                @foreach($this->pendingRequests as $joinRequest)
                    <div class="pk-row lg-person" wire:key="join-request-{{ $joinRequest->id }}">
                        <a href="{{ route('users.show', $joinRequest->user) }}" class="lg-avatar" aria-label="{{ $joinRequest->user->name }}"><x-pulse.avatar :name="$joinRequest->user->name" /></a>
                        <div class="pk-row__main">
                            <a href="{{ route('users.show', $joinRequest->user) }}" class="pk-row__title">{{ $joinRequest->user->name }}</a>
                            <span class="pk-row__meta">asked {{ $joinRequest->created_at->diffForHumans() }}</span>
                        </div>
                        <div class="pk-cluster">
                            <x-pulse.button size="sm" wire:click="approveRequest({{ $joinRequest->id }})">Approve</x-pulse.button>
                            <x-pulse.button variant="quiet" size="sm" wire:click="denyRequest({{ $joinRequest->id }})">Deny</x-pulse.button>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="pk-card" aria-labelledby="cposts-title">
        <h2 id="cposts-title" class="lg-h">Posts</h2>

        @if($this->posts->isEmpty())
            <p class="pk-muted lg-none">No posts in this community yet.</p>
        @else
            <div class="lg-list">
                @foreach($this->posts as $post)
                    <a href="{{ route('posts.show', $post) }}" class="pk-row lg-rowlink lg-cpost" wire:key="cpost-{{ $post->id }}">
                        <span class="pk-row__main">
                            <span class="lg-post__meta">
                                <span class="pk-row__title">{{ $post->author->name }}</span>
                                <x-pulse.kind-word :kind="str_replace('_', ' ', ucfirst($post->type))" />
                                <span class="lg-post__time">{{ $post->created_at->diffForHumans() }}</span>
                            </span>
                            @if($post->title)<span class="lg-post__title">{{ $post->title }}</span>@endif
                            <span class="pk-muted lg-clamp lg-clamp--2">{{ $post->body }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</div>
