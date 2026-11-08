<div>
    @unless($this->isSelf)
        @if($compact)
            <button wire:click="toggleFollow" type="button" class="lg-link" aria-pressed="{{ $this->isFollowing ? 'true' : 'false' }}">
                {{ $this->isFollowing ? 'Following' : 'Follow' }}
            </button>
        @else
            <x-pulse.button wire:click="toggleFollow" :variant="$this->isFollowing ? 'quiet' : 'primary'" aria-pressed="{{ $this->isFollowing ? 'true' : 'false' }}">
                {{ $this->isFollowing ? 'Following' : 'Follow' }}
            </x-pulse.button>
        @endif
    @endunless
</div>
