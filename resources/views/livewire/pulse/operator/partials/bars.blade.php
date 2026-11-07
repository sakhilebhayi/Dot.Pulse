{{--
    Horizontal bars for a few labelled counts, all one hue, drawn from zero so length is honest. Every bar carries its
    label and its number, so nothing depends on colour. Vars: title, rows (label => int), unit (noun for the table)
--}}
@php
    $max = max(1, ...array_values($rows ?: [0]));
@endphp
<figure class="op-bars">
    <figcaption class="op-bars__title">{{ $title }}</figcaption>
    <ul class="op-bars__list">
        @foreach ($rows as $barLabel => $n)
            <li>
                <span class="op-bars__label">{{ $barLabel }}</span>
                <span class="op-bars__track" aria-hidden="true"><span class="op-bars__fill" style="width: {{ round($n / $max * 100, 1) }}%"></span></span>
                <span class="op-bars__n">{{ number_format($n) }}</span>
            </li>
        @endforeach
    </ul>
</figure>
