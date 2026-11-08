{{--
    pulse/button
    A link or a button in the Floor's three voices. Primary (cobalt) moves things on, signal (yellow) is the one action
    that moves things forward on a screen, quiet is everything else. Renders <a> when href is given, <button> otherwise.
    Props: variant (primary|signal|quiet|ghost|danger), href, type (button|submit), size (md|sm), block (bool), icon (name)
    Extra attributes pass through (wire:click, data-*, aria-*). Tap target is 44px on phones.
--}}
@props(['variant' => 'primary', 'href' => null, 'type' => 'button', 'size' => 'md', 'block' => false, 'icon' => null])
@php
    $class = 'pk-btn pk-btn--'.$variant.($size === 'sm' ? ' pk-btn--sm' : '').($block ? ' pk-btn--block' : '');
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $class]) }}>@if($icon)<x-pulse.icon :name="$icon" :size="18" />@endif{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $class]) }}>@if($icon)<x-pulse.icon :name="$icon" :size="18" />@endif{{ $slot }}</button>
@endif
