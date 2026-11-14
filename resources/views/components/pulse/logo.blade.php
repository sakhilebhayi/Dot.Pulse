{{--
    pulse/logo
    The original Dot.Pulse logo, public/images/logo.png, exactly as supplied: never redrawn, recoloured or restyled.
    Only its presentation changes here: height, and the light plate behind it. The file was drawn for a light ground and
    there is no light-on-dark version, so on cobalt and dark surfaces it sits on a white plate (--c-logo-plate), in both themes.
    Props: height (px, default 52; the width follows the file's 1181:886 shape so it is never distorted)
--}}
@props(['height' => 52])
@php($width = (int) round($height * 1181 / 886))
<img {{ $attributes->merge(['class' => 'pk-logo']) }} src="{{ asset('images/logo.png') }}" alt="Dot.Pulse" width="{{ $width }}" height="{{ $height }}" decoding="async">
