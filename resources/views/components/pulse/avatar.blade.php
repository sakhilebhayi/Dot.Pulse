{{--
    pulse/avatar
    A person or a business. Initials when there is no picture. The yellow ring is the InfoDot member mark and is drawn only
    when the caller says the member status is real.
    Props: name, src (optional), size (sm|md|lg|xl), entity (bool, square-ish), member (bool)
--}}
@props(['name' => '', 'src' => null, 'size' => 'md', 'entity' => false, 'member' => false])
<span {{ $attributes->merge(['class' => 'pk-avatar'.($size !== 'md' ? ' pk-avatar--'.$size : '').($entity ? ' pk-avatar--entity' : '').($member ? ' pk-avatar--member' : '')]) }} @if($src) role="img" aria-label="{{ $name }}" @else aria-hidden="true" @endif>
    @if ($src)<img src="{{ $src }}" alt="" loading="lazy" decoding="async">@else{{ pulse_initials($name) }}@endif
</span>
