{{--
    pulse/mark
    The Dot.Pulse mark: two lines that run toward each other and cross at one yellow node.
    The first line takes currentColor, the second is always ink, the node is always signal yellow.
    Props: size (px, default 28)
--}}
@props(['size' => 28])
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="{{ $size }}" height="{{ $size }}" fill="none" aria-hidden="true" focusable="false" {{ $attributes }}>
    <path d="M3 9c8 0 8 14 17 14h9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
    <path d="M3 23c8 0 8-14 17-14h9" stroke="var(--c-ink-fixed)" stroke-width="3" stroke-linecap="round"/>
    <circle cx="11.5" cy="16" r="4" fill="var(--c-signal)" stroke="var(--c-ink-fixed)" stroke-width="2"/>
</svg>
