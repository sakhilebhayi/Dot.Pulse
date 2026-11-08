{{-- Typed-ahead place picker over the gazetteer (PicksPlace trait). --}}
<div class="me-place">
    @if ($placeId)
        <div class="me-place__chosen">
            <x-pulse.place-label :name="$this->chosenPlaceName()" />
            <x-pulse.button variant="quiet" size="sm" wire:click="clearPlace" type="button">Change</x-pulse.button>
        </div>
    @else
        <x-pulse.field name="placeQuery" label="Your city or region" hint="Type at least two letters." wire:model.live.debounce.200ms="placeQuery" autocomplete="off" placeholder="Soweto, Cape Town, Gauteng" />
        @php $results = $this->placeResults(); @endphp
        @if ($results->isNotEmpty())
            <ul class="me-place__results" aria-label="Places">
                @foreach ($results as $place)
                    <li wire:key="place-{{ $place->id }}"><button type="button" class="me-result" wire:click="pickPlace({{ $place->id }})"><x-pulse.icon name="pin" :size="16" /><span>{{ $this->placeLabel($place) }}</span><span class="me-result__level">{{ $place->level }}</span></button></li>
                @endforeach
            </ul>
        @elseif (mb_strlen(trim($placeQuery)) >= 2)
            <p class="pk-field__hint">Nothing by that name. Try the nearest city or your region.</p>
        @endif
    @endif
</div>
