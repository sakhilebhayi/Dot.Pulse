<div>
    @if($this->people->isEmpty())
        <p class="pk-muted lg-none">{{ $mode === 'followers' ? 'No followers yet.' : 'Not following anyone yet.' }}</p>
    @else
        <div class="lg-list">
            @foreach($this->people as $person)
                <div class="pk-row lg-person" wire:key="person-{{ $person->id }}">
                    @php $hidden = \App\Models\PulseProfile::isHiddenFrom($person, auth()->user()); @endphp
                    @if($hidden)
                        <span class="lg-avatar"><x-pulse.avatar name="?" /></span>
                        <div class="pk-row__main">
                            <span class="pk-row__title">{{ \App\Models\PulseProfile::publicName($person, auth()->user()) }}</span>
                            <span class="pk-row__meta lg-trunc">Keeps their profile private.</span>
                        </div>
                    @else
                        <a href="{{ route('users.show', $person) }}" class="lg-avatar" aria-label="{{ $person->name }}"><x-pulse.avatar :name="$person->name" /></a>
                        <div class="pk-row__main">
                            <a href="{{ route('users.show', $person) }}" class="pk-row__title">{{ $person->name }}</a>
                            @if($headline = \App\Models\PulseProfile::publicHeadline($person, auth()->user()))<span class="pk-row__meta lg-trunc">{{ $headline }}</span>@endif
                        </div>
                        <livewire:pulse.follow-button :user="$person" :compact="true" wire:key="follow-{{ $person->id }}-list-{{ $mode }}" />
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
