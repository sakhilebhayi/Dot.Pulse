{{--
    pulse/place-label
    A declared place, coarse by design (city, region, country). Never a coordinate. Remote prints as Remote.
    Props: name (e.g. "Soweto, Gauteng"), remote (bool), reach (local|region|national|global, optional)
--}}
@props(['name' => null, 'remote' => false, 'reach' => null])
@php
    $reachWords = ['local' => 'Nearby', 'region' => 'In the region', 'national' => 'Nationwide', 'global' => 'Anywhere'];
    $text = $remote ? 'Remote' : ($name ?: ($reachWords[$reach] ?? null));
@endphp
@if ($text)
    <span {{ $attributes->merge(['class' => 'pk-place']) }}><x-pulse.icon name="pin" :size="14" />{{ $text }}</span>
@endif
