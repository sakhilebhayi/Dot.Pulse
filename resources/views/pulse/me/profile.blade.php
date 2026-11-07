<x-app-layout title="Your profile" width="wide">
    <div class="me-page">
        @include('livewire.pulse.me.partials.tabs', ['active' => 'profile', 'lede' => 'What people read about you, once you let them.'])
        <livewire:pulse.me.profile-editor />
    </div>
</x-app-layout>
