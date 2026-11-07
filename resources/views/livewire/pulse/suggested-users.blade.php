<div>
    @if($this->suggestions->isEmpty())
        <p class="pk-muted">No suggestions yet. Check back once more people join.</p>
    @else
        <div class="lg-list">
            @foreach($this->suggestions as $person)
                <div class="pk-row lg-person" wire:key="suggested-{{ $person->id }}">
                    <a href="{{ route('users.show', $person) }}" class="lg-avatar" aria-label="{{ $person->name }}"><x-pulse.avatar :name="$person->name" /></a>
                    <div class="pk-row__main">
                        <a href="{{ route('users.show', $person) }}" class="pk-row__title">{{ $person->name }}</a>
                        @if($person->pulseProfile?->headline)<span class="pk-row__meta lg-trunc">{{ $person->pulseProfile->headline }}</span>@endif
                    </div>
                    <livewire:pulse.follow-button :user="$person" :compact="true" wire:key="follow-{{ $person->id }}-suggested" />
                </div>
            @endforeach
        </div>
    @endif
</div>
