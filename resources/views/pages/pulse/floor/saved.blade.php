<x-app-layout title="Saved" width="floor" :dial="false">
    <x-slot name="header">
        <div>
            <h1 class="fl-h1">Saved</h1>
            <p>Beats you kept for later. They leave the round, so you will not see them twice.</p>
        </div>
    </x-slot>

    <livewire:pulse.floor.saved />
    <livewire:pulse.knock-sheet :bare="true" />
</x-app-layout>
