<x-app-layout title="Privacy" width="wide">
    <div class="me-page">
        @include('livewire.pulse.me.partials.tabs', ['active' => 'privacy', 'lede' => 'Every permission Pulse relies on, in plain words, with one tap to take it back.'])
        <livewire:pulse.me.privacy />
    </div>
</x-app-layout>
