<div class="fl-round" x-data>
    <div class="fl-draw" wire:loading.delay.shortest wire:target="unsave,save" aria-hidden="true"></div>

    @if ($beats === [])
        <x-pulse.empty title="Nothing saved yet." text="Tap Save on a beat and it waits here until you are ready. Saved beats leave the round, so you will not see them twice.">
            <x-pulse.button variant="signal" :href="pulse_route('floor')">Back to the floor</x-pulse.button>
        </x-pulse.empty>
    @else
        @if (count($tabs) > 2)
            <x-pulse.tabs :tabs="$tabs" :active="$kind" label="Saved, by kind" />
        @endif

        <div class="pk-enter fl-list">
            @forelse ($shown as $b)
                @include('livewire.pulse.floor.partials.beat', ['b' => $b, 'mode' => 'saved', 'why' => []])
            @empty
                <x-pulse.empty title="Nothing of that kind." text="Pick another kind, or go back to all of them." />
            @endforelse
        </div>
    @endif
</div>
