<x-app-layout title="Notifications" width="wide">
    <div class="me-page">
        @include('livewire.pulse.me.partials.tabs', ['active' => 'notifications', 'lede' => 'What happened, and what is allowed to reach you.'])
        <livewire:pulse.me.invitations />
        <div class="me-split">
            <livewire:pulse.me.notification-list />
            <livewire:pulse.me.notification-settings />
        </div>
    </div>
</x-app-layout>
