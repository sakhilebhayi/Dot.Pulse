<x-app-layout>

{{-- data-post-id anchors resources/js/pulse-realtime.js's per-post
     comment-channel subscription; a stable page-level element rather than
     something inside the Livewire component's own re-rendered root. --}}
<div data-post-id="{{ $post->id }}" class="pulse-page">

    <a href="{{ route('dashboard') }}" style="font-size:0.78rem;color:#52525b;text-decoration:none;">&larr; Back to feed</a>

    <div style="margin-top:1rem;">
        <livewire:pulse.post-show :post="$post" />
    </div>

</div>

</x-app-layout>
