<section class="pk-card lg-communities" aria-labelledby="communities-title">
    <div class="lg-toolbar">
        <h2 id="communities-title" class="lg-h">Communities</h2>
        <div class="lg-toolbar__type">
            <x-dot.filter-search id="community-search" name="search" label="Search communities" placeholder="Search communities" wire:model.live="search" />
        </div>
    </div>

    @if($this->communities->isEmpty())
        <p class="pk-muted lg-none">No communities found.</p>
    @else
        <div class="lg-cgrid">
            @foreach($this->communities as $community)
                @php
                    $isMember = isset($this->memberOf[$community->id]);
                    $requestStatus = $this->requests[$community->id] ?? null;
                    $canOpen = $community->isPublic() || $isMember;
                @endphp
                <div class="lg-community" wire:key="community-{{ $community->id }}">
                    <div class="lg-community__main">
                        <div class="lg-community__name">
                            @if($canOpen)
                                <a href="{{ route('communities.show', $community) }}">{{ $community->name }}</a>
                            @else
                                <span>{{ $community->name }}</span>
                            @endif
                            @if($community->isPrivate())
                                <x-pulse.pill icon="lock" title="Only members can see what is posted here">Private</x-pulse.pill>
                            @endif
                        </div>
                        @if($community->industry)<p class="lg-community__industry">{{ $community->industry }}</p>@endif
                        @if($canOpen)
                            <p class="pk-muted lg-clamp lg-clamp--2">{{ $community->description }}</p>
                        @else
                            <p class="pk-muted">Posts and details are visible to members only.</p>
                        @endif
                        <p class="pk-muted lg-small">{{ number_format($community->members_count) }} {{ \Illuminate\Support\Str::plural('member', $community->members_count) }}</p>
                    </div>
                    <div class="lg-community__act">
                        @if($isMember)
                            <x-pulse.button variant="quiet" size="sm" wire:click="leave({{ $community->id }})">Leave</x-pulse.button>
                        @elseif($requestStatus === 'pending')
                            <x-pulse.button variant="quiet" size="sm" wire:click="cancelRequest({{ $community->id }})" title="Withdraw your request">Requested</x-pulse.button>
                        @elseif(isset($this->coolingDown[$community->id]))
                            <x-pulse.pill title="A manager declined your request. You can ask again later.">Not accepted</x-pulse.pill>
                        @elseif($community->isPrivate())
                            <x-pulse.button size="sm" wire:click="join({{ $community->id }})">Request to join</x-pulse.button>
                        @else
                            <x-pulse.button size="sm" wire:click="join({{ $community->id }})">Join</x-pulse.button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</section>
