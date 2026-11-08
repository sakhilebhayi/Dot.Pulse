{{--
    pulse/tabs
    Two modes. With hrefs, it is a nav of links and the current one carries aria-current (server pages). Without hrefs, it is a real
    tablist: buttons with arrow-key movement, and the slot holds panels you mark with data-pk-panel="key".

    Props: tabs  array of key => label, or key => ['label' => ..., 'href' => ..., 'count' => ...]
           active  key of the selected tab
           label  accessible name for the list
--}}
@props(['tabs' => [], 'active' => null, 'label' => 'Sections'])
@php
    $items = collect($tabs)->map(fn ($t, $k) => is_array($t) ? $t + ['key' => $k] : ['key' => $k, 'label' => $t]);
    $linked = $items->contains(fn ($t) => ! empty($t['href']));
    $active = $active ?? ($items->first()['key'] ?? null);
@endphp
@if ($linked)
    <nav aria-label="{{ $label }}" {{ $attributes }}>
        <ul class="pk-tabs__list">
            @foreach ($items as $t)
                <li><a class="pk-tab" href="{{ $t['href'] ?? '#' }}" @if ($t['key'] == $active) aria-current="page" @endif>{{ $t['label'] }}@if (! empty($t['count']))<span class="pk-tab__count">{{ $t['count'] }}</span>@endif</a></li>
            @endforeach
        </ul>
    </nav>
@else
    <div data-pk-tabs {{ $attributes }}>
        <div class="pk-tabs__list" role="tablist" aria-label="{{ $label }}">
            @foreach ($items as $t)
                <button type="button" class="pk-tab" role="tab" data-pk-tab="{{ $t['key'] }}" aria-selected="{{ $t['key'] == $active ? 'true' : 'false' }}" tabindex="{{ $t['key'] == $active ? '0' : '-1' }}">{{ $t['label'] }}@if (! empty($t['count']))<span class="pk-tab__count">{{ $t['count'] }}</span>@endif</button>
            @endforeach
        </div>
        {{ $slot }}
    </div>
@endif
