<x-app-layout title="Discoverability" width="wide">
    <div class="me-page">
        @include('livewire.pulse.me.partials.tabs', ['active' => 'discover', 'lede' => 'You decide whether employers find you, and how much they see.'])
        <livewire:pulse.me.discoverability />
    </div>
</x-app-layout>
