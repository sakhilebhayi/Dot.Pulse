<x-app-layout title="Settings" width="wide">
    <div class="me-page">
        @include('livewire.pulse.me.partials.tabs', ['active' => 'settings', 'lede' => 'Who can start a conversation with you, and where you are.'])
        <livewire:pulse.me.settings />
    </div>
</x-app-layout>
