{{--
    pulse/pill
    A small state label. Tone changes the colour and the icon, and the words always say it too.
    Props: tone (neutral|good|warn|bad|signal), icon (name, optional; good and warn and bad get one by default)
--}}
@props(['tone' => 'neutral', 'icon' => null])
@php
    $default = ['good' => 'check', 'warn' => 'alert', 'bad' => 'alert'][$tone] ?? null;
    $glyph = $icon ?? $default;
@endphp
<span {{ $attributes->merge(['class' => 'pk-pill'.($tone !== 'neutral' ? ' pk-pill--'.$tone : '')]) }}>@if($glyph)<x-pulse.icon :name="$glyph" :size="14" />@endif{{ $slot }}</span>
