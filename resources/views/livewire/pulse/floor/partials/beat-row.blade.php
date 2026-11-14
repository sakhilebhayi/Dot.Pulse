{{--
    One beat as a 56px row on the Floor. The whole row is the link to the beat's own page; on a wide screen a click
    selects it instead (the pane beside the rows shows it, see Round::select). Same array BeatPresenter makes.
    Needs: $b (the beat array), $selected (signal id shown in the pane or null), optional $quiet (seen earlier).
    The row keeps data-beat and tabindex="-1" so the round's n / p / s / Enter keys find it.
--}}
@php
    $sid = (int) $b['signal_id'];
    $anon = (bool) ($b['anonymous'] ?? false);
    $reasons = $b['reasons'] ?? [];
    $count = count($reasons);
    $quiet = $quiet ?? false;
    $isSelected = isset($selected) && (int) $selected === $sid;
    $fromName = $anon ? 'Anonymous professional' : ($b['from']['name'] ?? 'Someone on Pulse');
    $isBusiness = ($b['kind'] ?? '') === 'business';
    $bits = array_values(array_filter([
        $isBusiness ? null : $fromName,
        $b['place']['label'] ?? null,
        (! $anon && ! empty($b['verification'])) ? $b['verification']['label'] : null,
    ]));
@endphp
<li
    wire:key="row-{{ $sid }}"
    class="fl-row{{ $quiet ? ' fl-row--quiet' : '' }}"
    data-beat="{{ $sid }}"
    data-row
    tabindex="-1"
    @if ($isSelected) aria-current="true" @endif
    x-data
>
    <x-pulse.kind-word :kind="$b['kind']" class="fl-row__kind" />
    <div class="fl-row__text">
        <a class="fl-row__open" href="{{ $b['url'] ?? route('signals.show', $sid) }}"
           x-on:click="if (window.matchMedia('(min-width: 1200px)').matches) { $event.preventDefault(); $wire.select({{ $sid }}) }">{{ $b['title'] }}</a>
        <small>{{ implode(', ', $bits) }}</small>
    </div>
    <span class="fl-row__why{{ $count === 0 ? ' fl-row__why--none' : '' }}">
        @if ($count > 0)
            <span class="fl-row__dots" aria-hidden="true">@for ($i = 0; $i < min($count, 3); $i++)<i></i>@endfor</span>
            {{ \App\Support\Moment::plural($count, 'reason') }}
        @else
            Nearby only
        @endif
    </span>
    @if (! empty($b['when']))<span class="fl-row__when pk-mono">{{ $b['when'] }}</span>@endif
</li>
