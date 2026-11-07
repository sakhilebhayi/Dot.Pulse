{{--
    pulse/brand
    The mark and the wordmark as one link. Sits on the cobalt masthead (white wordmark).
    Props: href (default the Floor, or the home page for signed-out visitors)
--}}
@props(['href' => null])
<a href="{{ $href ?? (auth()->check() ? pulse_route_first(['floor', 'dashboard']) : url('/')) }}" {{ $attributes->merge(['class' => 'sh-brand']) }} aria-label="Dot.Pulse home">
    <x-pulse.mark :size="30" style="color: var(--c-field-ink)" />
    <span class="sh-brand__word">Dot.Pulse</span>
</a>
