{{-- The Me hub: one title, one line of tabs. Variables: $active (profile|discover|settings|notifications|privacy|account), $lede --}}
@php
    $tabs = [
        'profile' => ['label' => 'Profile', 'href' => pulse_route('me.profile')],
        'discover' => ['label' => 'Discoverability', 'href' => pulse_route('me.discover')],
        'settings' => ['label' => 'Settings', 'href' => pulse_route('me.settings')],
        'notifications' => ['label' => 'Notifications', 'href' => pulse_route('me.notifications')],
        'privacy' => ['label' => 'Privacy', 'href' => pulse_route('me.privacy')],
        'account' => ['label' => 'Account', 'href' => pulse_route('me.account')],
    ];
@endphp
<div class="me-head">
    <h1 class="me-title">You</h1>
    @isset($lede)<p class="me-lede">{{ $lede }}</p>@endisset
    @if (count($mine = pulse_my_businesses()))
        <p class="me-biz">
            <span>{{ count($mine) === 1 ? 'Your business' : 'Your businesses' }}</span>
            @foreach ($mine as $b)
                <a href="{{ pulse_route('entities.manage', ['entity' => $b['slug']]) }}">Manage {{ $b['name'] }}</a>
            @endforeach
        </p>
    @endif
    <div x-data x-init="$nextTick(() => $el.querySelector('[aria-current]')?.scrollIntoView({ inline: 'center', block: 'nearest' }))">
        <x-pulse.tabs :tabs="$tabs" :active="$active" label="Your pages" class="me-tabs" />
    </div>
</div>
