{{--
    Knocks sent per day. One series, bars from a zero baseline, the axis ticks are 0, half and the top of the scale, and the
    first, middle and last dates are printed. A table of the same numbers follows for anyone who prefers it.
    Vars: series (list of {date, value}), noun
--}}
@php
    $n = count($series);
    $peak = max(1, ...array_column($series, 'value'));
    $step = 10 ** floor(log10($peak));
    $top = (int) (ceil($peak / $step) * $step);
    if ($top / $step > 5) { $step *= 2; $top = (int) (ceil($peak / $step) * $step); }
    if ($top % 2 === 1) { $top += 1; }
    $W = 640; $H = 190; $L = 38; $R = 6; $T = 10; $B = 28;
    $plotW = $W - $L - $R; $plotH = $H - $T - $B;
    $slot = $plotW / max(1, $n);
    $gap = $n > 45 ? 1 : 2;
    $barW = max(1, $slot - $gap);
    $y = fn ($v) => $T + $plotH - ($v / $top) * $plotH;
    $fmt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('j M');
    $total = array_sum(array_column($series, 'value'));
@endphp
<figure class="op-daily">
    <figcaption class="op-bars__title">{{ ucfirst($noun) }} per day</figcaption>
    <svg viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="{{ ucfirst($noun) }} per day, {{ $fmt($series[0]['date']) }} to {{ $fmt($series[$n - 1]['date']) }}. {{ $total }} in total. The busiest day had {{ $peak }}.">
        @foreach ([0, $top / 2, $top] as $tick)
            <line x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $y($tick) }}" y2="{{ $y($tick) }}" class="{{ $tick === 0 ? 'op-axis' : 'op-gl' }}" />
            <text x="{{ $L - 6 }}" y="{{ $y($tick) + 4 }}" text-anchor="end" class="op-tick">{{ number_format($tick) }}</text>
        @endforeach
        @foreach ($series as $i => $point)
            @if ($point['value'] > 0)
                <rect x="{{ round($L + $i * $slot + $gap / 2, 2) }}" y="{{ round($y($point['value']), 2) }}" width="{{ round($barW, 2) }}" height="{{ round($plotH - ($y($point['value']) - $T), 2) }}" rx="{{ $barW > 6 ? 3 : 1 }}" class="op-bar"><title>{{ $fmt($point['date']) }}: {{ $point['value'] }}</title></rect>
            @endif
        @endforeach
        <text x="{{ $L }}" y="{{ $H - 8 }}" text-anchor="start" class="op-tick">{{ $fmt($series[0]['date']) }}</text>
        @if ($n > 6)<text x="{{ $L + $plotW / 2 }}" y="{{ $H - 8 }}" text-anchor="middle" class="op-tick">{{ $fmt($series[intdiv($n, 2)]['date']) }}</text>@endif
        <text x="{{ $W - $R }}" y="{{ $H - 8 }}" text-anchor="end" class="op-tick">{{ $fmt($series[$n - 1]['date']) }}</text>
    </svg>
    <details class="op-table">
        <summary>Show the numbers as a table</summary>
        <table>
            <thead><tr><th scope="col">Day</th><th scope="col">{{ ucfirst($noun) }}</th></tr></thead>
            <tbody>
                @foreach (array_reverse($series) as $point)
                    <tr><th scope="row">{{ $fmt($point['date']) }}</th><td>{{ $point['value'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </details>
</figure>
