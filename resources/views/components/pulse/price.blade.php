{{--
    pulse/price
    A price in mono with tabular figures. Nothing is invented: no amount prints "On request".
    Props: amount (number|null), to (number|null, makes a range), currency (default ZAR), unit (e.g. "per hour"), large (bool)
--}}
@props(['amount' => null, 'to' => null, 'currency' => 'ZAR', 'unit' => null, 'large' => false])
@php
    $symbols = ['ZAR' => 'R', 'USD' => '$', 'EUR' => 'EUR ', 'GBP' => 'GBP '];
    $sym = $symbols[strtoupper($currency)] ?? strtoupper($currency).' ';
    $fmt = fn ($n) => $sym.number_format((float) $n, ((float) $n == floor((float) $n)) ? 0 : 2, '.', ' ');
@endphp
<span {{ $attributes->merge(['class' => 'pk-price'.($large ? ' pk-price--lg' : '')]) }}>
    @if ($amount === null)
        <span class="pk-price__words">On request</span>
    @else
        <span>{{ $fmt($amount) }}@if ($to !== null && (float) $to !== (float) $amount) to {{ $fmt($to) }}@endif</span>
        @if ($unit)<small>{{ $unit }}</small>@endif
    @endif
</span>
