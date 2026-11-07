<x-app-layout :title="ucfirst($signal->kind)" width="floor" :dial="false">
    <x-slot name="header">
        <div>
            <a class="fl-back" href="{{ pulse_route('floor') }}"><x-pulse.icon name="chevron" :size="16" class="fl-back__icon" />Back to the floor</a>
        </div>
    </x-slot>

    <livewire:pulse.floor.beat-detail :signal="$signal" />
    <livewire:pulse.knock-sheet :bare="true" />
</x-app-layout>
