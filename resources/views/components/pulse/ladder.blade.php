{{--
    pulse/ladder
    The verification ladder: named checks in order. A checked rung is a filled mark, a claim is a hollow ring labelled "Claimed",
    a missing rung is a dashed ring. Colour is never the only signal: every rung says its state in words, with the date and the
    source when there is one. Built only from real data (VerificationService::ladder); never invent a rung.

    Props: rungs  array of ['key','label','state' => checked|claimed|missing, 'at' => date|string|null, 'source' => ?string, 'detail' => ?string]
           summary (bool)  print "N of M checked" above the list
--}}
@props(['rungs' => [], 'summary' => false])
@php
    $rows = collect($rungs)->map(function ($r) {
        // Dates and sources arrive as services store them (ISO times, snake_case codes); the ladder says them in words.
        $at = \App\Support\Moment::date(data_get($r, 'at')) ?: null;
        return [
            'label' => (string) data_get($r, 'label', ''),
            'state' => in_array(data_get($r, 'state'), ['checked', 'claimed', 'missing'], true) ? data_get($r, 'state') : 'missing',
            'at' => $at ?: null,
            'source' => \App\Support\Moment::source(data_get($r, 'source')),
            'detail' => data_get($r, 'detail'),
        ];
    })->filter(fn ($r) => $r['label'] !== '')->values();
    $words = ['checked' => 'Checked', 'claimed' => 'Claimed by them, not checked', 'missing' => 'Not shown'];
    $checked = $rows->where('state', 'checked')->count();
@endphp
@if ($rows->isNotEmpty())
    <div {{ $attributes }}>
        @if ($summary)
            <p class="pk-ladder-sum" style="margin-bottom: 12px"><x-pulse.icon name="shield" :size="16" />{{ $checked }} of {{ $rows->count() }} checked</p>
        @endif
        <ol class="pk-ladder">
            @foreach ($rows as $rung)
                <li class="pk-rung pk-rung--{{ $rung['state'] }}">
                    <span class="pk-rung__mark" aria-hidden="true">@if ($rung['state'] === 'checked')<x-pulse.icon name="check" :size="14" />@endif</span>
                    <div>
                        <div class="pk-rung__label">{{ $rung['label'] }}<span class="pk-rung__state">{{ $words[$rung['state']] }}</span></div>
                        @if ($rung['at'] || $rung['source'] || $rung['detail'])
                            <div class="pk-rung__meta">
                                @if ($rung['at'])<span class="pk-mono">{{ $rung['at'] }}</span>@endif
                                @if ($rung['source'])<span>Source: {{ $rung['source'] }}</span>@endif
                                @if ($rung['detail'])<span>{{ $rung['detail'] }}</span>@endif
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </div>
@endif
