<div>
    @if($this->people->isEmpty())
        <p class="pk-muted lg-none">{{ $mode === 'followers' ? 'No followers yet.' : 'Not following anyone yet.' }}</p>
    @else
        <div class="lg-list">
            @foreach($this->people as $person)
                <div class="pk-row lg-person" wire:key="person-{{ $person->id }}">
                    <a href="{{ route('users.show', $person) }}" class="lg-avatar" aria-label="{{ $person->name }}"><x-pulse.avatar :name="$person->name" /></a>
                    <div class="pk-row__main">
                        <a href="{{ route('users.show', $person) }}" class="pk-row__title">{{ $person->name }}</a>
                        @if($person->pulseProfile?->headline)<span class="pk-row__meta lg-trunc">{{ $person->pulseProfile->headline }}</span>@endif
                    </div>
                    <livewire:pulse.follow-button :user="$person" :compact="true" wire:key="follow-{{ $person->id }}-list-{{ $mode }}" />
                </div>
            @endforeach
        </div>
    @endif
</div>
