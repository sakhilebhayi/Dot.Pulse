{{--
    pulse/icon
    Stroke icons on a 24 grid, drawn with currentColor. Decorative by default (aria-hidden); pass a label to name one.
    Props: name (floor trade work lines you search bell sun moon plus x check menu pin alert bookmark door lock
           shield chart info chevron user), size (px, default 20), label (optional accessible name)
--}}
@props(['name', 'size' => 20, 'label' => null])
@php
    $paths = [
        'floor' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'trade' => '<path d="M7 4 3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"/>',
        'work' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2M2 13h20"/>',
        'lines' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/>',
        'you' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
        'moon' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'pin' => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'alert' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        'bookmark' => '<path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2Z"/>',
        'door' => '<path d="M13 4h3a2 2 0 0 1 2 2v14M2 20h20M13 20V4.5a1 1 0 0 0-1.2-1L6 4.9a1 1 0 0 0-.8 1V20"/><path d="M10 12v.01"/>',
        'lock' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
        'chart' => '<path d="M3 3v18h18M7 14l4-4 4 4 5-6"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
    ];
    $body = $paths[$name] ?? $paths['info'];
@endphp
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="{{ $size }}" height="{{ $size }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" @if($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" focusable="false" @endif {{ $attributes }}>{!! $body !!}</svg>
