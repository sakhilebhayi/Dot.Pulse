<x-app-layout title="Search" width="floor" :dial="false">
    <x-slot name="header">
        <div>
            <h1 class="fl-h1">Search</h1>
            <p>Offers, needs, roles, people and businesses. Every result says why.</p>
        </div>
    </x-slot>

    <livewire:pulse.floor.search />
    <livewire:pulse.knock-sheet :bare="true" />
</x-app-layout>
