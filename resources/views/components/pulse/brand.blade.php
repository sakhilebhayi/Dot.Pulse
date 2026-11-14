{{--
    pulse/brand
    The original logo as a link, on the masthead. The logo sits on its light plate (see pulse/logo).
    Props: href (default the Floor, or the home page for signed-out visitors)
--}}
@props(['href' => null])
<a href="{{ $href ?? (auth()->check() ? pulse_route_first(['floor', 'dashboard']) : url('/')) }}" {{ $attributes->merge(['class' => 'sh-brand']) }} aria-label="Dot.Pulse home">
    <x-pulse.logo :height="52" class="sh-brand__logo" />
</a>
