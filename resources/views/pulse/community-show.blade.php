<x-app-layout :title="$community->name" width="floor" :dial="false">
    <div class="pk-stack">
        <div><x-pulse.button variant="quiet" size="sm" :href="pulse_route_first(['floor', 'dashboard'])">Back to the feed</x-pulse.button></div>
        <livewire:pulse.community-show :community="$community" />
    </div>
</x-app-layout>
