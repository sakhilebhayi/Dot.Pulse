<x-app-layout title="Open door" width="floor" :dial="false">
    <x-slot name="header">
        <div>
            <h1 class="fl-h1">Open a door</h1>
            <p>Let strangers knock once, for a day or a week, about one thing.</p>
        </div>
    </x-slot>

    <livewire:pulse.floor.door />
</x-app-layout>
