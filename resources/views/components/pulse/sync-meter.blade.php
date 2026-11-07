{{--
    pulse/sync-meter  (the signature)
    Your line (cobalt) and their line (ink) run toward each other and cross once for every reason the two fit. Each crossing
    carries a yellow node and each node has its reason as a plain sentence. It draws from the real reasons[] of a match and from
    nothing else: with no reasons it renders nothing, because a match with no reasons does not exist.

    The lines are two stacked SVGs (revealed by clip-path, so the stroke stays 3px at any width); nodes are HTML so they stay 14px. It draws once on first view (shell.js adds the classes)
    and is complete under reduced motion or without scripting.

    Props: reasons  array of ['code' => 'need_offer', 'text' => 'You need bookkeeping, they offer bookkeeping', 'weight' => 30]
           youLabel, themLabel  end labels (default You and Them), pass '' to hide the ends
           remove   Livewire method name; when set each reason gets a "Stop using this" button calling remove('code'),
                    which is how a person taps a reason off (Floor::removeReason)
--}}
@props(['reasons' => [], 'youLabel' => 'You', 'themLabel' => 'Them', 'remove' => null])
@php
    $items = collect($reasons)->map(fn ($r) => [
        'code' => (string) data_get($r, 'code', ''),
        'text' => trim((string) data_get($r, 'text', '')),
    ])->filter(fn ($r) => $r['text'] !== '')->values();
    $n = $items->count();
@endphp
@if ($n > 0)
    @php
        $w = 600;
        $amp = 36;
        $d = $w / $n;
        $you = [];
        $them = [];
        for ($x = 0; $x <= $w; $x += 6) {
            $s = sin(M_PI * ($x - $d / 2) / $d);
            $you[] = [$x, 50 + $amp * $s];
            $them[] = [$x, 50 - $amp * $s];
        }
        $path = fn (array $pts) => 'M'.implode(' L', array_map(fn ($p) => round($p[0], 1).' '.round($p[1], 1), $pts));
        $dYou = $path($you);
        $dThem = $path(array_reverse($them));
        $summary = ($n === 1 ? 'Your line and their line meet at 1 reason: ' : 'Your line and their line meet at '.$n.' reasons: ').$items->pluck('text')->implode('; ').'.';
    @endphp
    <figure {{ $attributes->merge(['class' => 'pk-meter'.($n <= 3 ? ' pk-meter--cols' : '')]) }} data-pk-meter style="--n: {{ $n }}">
        <div class="pk-meter__plot" role="img" aria-label="{{ $summary }}">
            <svg class="pk-meter__svg pk-meter__svg--you" viewBox="0 0 {{ $w }} 100" preserveAspectRatio="none" focusable="false" aria-hidden="true">
                <path class="pk-meter__line pk-meter__line--you" d="{{ $dYou }}"/>
            </svg>
            <svg class="pk-meter__svg pk-meter__svg--them" viewBox="0 0 {{ $w }} 100" preserveAspectRatio="none" focusable="false" aria-hidden="true">
                <path class="pk-meter__line pk-meter__line--them" d="{{ $dThem }}"/>
            </svg>
            @foreach ($items as $i => $reason)
                <span class="pk-meter__node" style="left: {{ round(($i + 0.5) / $n * 100, 2) }}%; --i: {{ $i }}" aria-hidden="true"></span>
            @endforeach
        </div>
        @if ($youLabel !== '' || $themLabel !== '')
            <div class="pk-meter__ends" aria-hidden="true"><span>{{ $youLabel }}</span><span>{{ $themLabel }}</span></div>
        @endif
        <ul class="pk-meter__reasons">
            @foreach ($items as $i => $reason)
                <li class="pk-meter__reason" style="--i: {{ $i }}">
                    <span class="pk-meter__tick" aria-hidden="true"></span>
                    <span>{{ $reason['text'] }}</span>
                    @if ($remove && $reason['code'] !== '')
                        <button type="button" wire:click="{{ $remove }}('{{ $reason['code'] }}')" aria-label="Stop using this reason: {{ $reason['text'] }}">Stop using this</button>
                    @endif
                </li>
            @endforeach
        </ul>
    </figure>
@endif
