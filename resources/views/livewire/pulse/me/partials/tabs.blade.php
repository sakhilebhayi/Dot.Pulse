{{-- The Me hub: one title, one line of tabs. Variables: $active (profile|discover|settings|notifications|privacy), $lede --}}
@php
    $tabs = [
        'profile' => ['label' => 'Profile', 'href' => pulse_route('me.profile')],
        'discover' => ['label' => 'Discoverability', 'href' => pulse_route('me.discover')],
        'settings' => ['label' => 'Settings', 'href' => pulse_route('me.settings')],
        'notifications' => ['label' => 'Notifications', 'href' => pulse_route('me.notifications')],
        'privacy' => ['label' => 'Privacy', 'href' => pulse_route('me.privacy')],
    ];
@endphp
<div class="me-head">
    <h1 class="me-title">You</h1>
    @isset($lede)<p class="me-lede">{{ $lede }}</p>@endisset
    <div x-data x-init="$nextTick(() => $el.querySelector('[aria-current]')?.scrollIntoView({ inline: 'center', block: 'nearest' }))">
        <x-pulse.tabs :tabs="$tabs" :active="$active" label="Your pages" class="me-tabs" />
    </div>
</div>
