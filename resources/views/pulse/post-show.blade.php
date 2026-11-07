{{-- data-post-id anchors resources/js/pulse-realtime.js's per-post comment-channel subscription; a stable page-level
     element rather than something inside the Livewire component's own re-rendered root. --}}
<x-app-layout title="Post" width="floor" :dial="false">
    <div data-post-id="{{ $post->id }}" class="pk-stack">
        <div><x-pulse.button variant="quiet" size="sm" :href="pulse_route_first(['floor', 'dashboard'])">Back to the feed</x-pulse.button></div>
        <livewire:pulse.post-show :post="$post" />
    </div>
</x-app-layout>
