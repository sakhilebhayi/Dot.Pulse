{{--
    The selected beat, beside the rows: place, title, who it is from, the sync meter with its reasons, and the actions.
    Same actions as the card (Knock, Save, Not for me), same wire methods (see Concerns\ActsOnBeats).
    Needs: $b (the beat array).
--}}
@php
    $sid = (int) $b['signal_id'];
    $anon = (bool) ($b['anonymous'] ?? false);
    $reasons = $b['reasons'] ?? [];
    $meter = \App\Livewire\Pulse\Floor\Support\MeterHtml::render($reasons, $b['match_id'] ?? null, ! empty($b['match_id']));
    $aside = $b['aside'] ?? null;
    $fromName = $anon ? 'Anonymous professional' : ($b['from']['name'] ?? 'Someone on Pulse');
@endphp
<aside class="fl-pane" aria-labelledby="fl-pane-h" data-floor-pane data-pane-beat="{{ $sid }}" wire:key="pane-{{ $sid }}">
    <div class="fl-pane__line">
        <x-pulse.kind-word :kind="$b['kind']" />
        @if (! empty($b['place']['label']))<span>{{ $b['place']['label'] }}</span>@endif
        @if (! empty($b['near']))<span>Near you</span>@endif
        @if (! empty($b['when']))<span class="pk-mono">{{ $b['when'] }}</span>@endif
    </div>
    <h2 id="fl-pane-h" class="fl-pane__title">{{ $b['title'] }}</h2>
    <p class="fl-pane__from">{{ $fromName }}</p>
    @if (! $anon && ! empty($b['verification']))
        <p><x-pulse.pill :tone="$b['verification']['tone']">{{ $b['verification']['label'] }}</x-pulse.pill></p>
    @endif
    @if ($anon)
        <p class="fl-pane__excerpt">{{ collect($b['card']['skills'] ?? [])->take(6)->implode(', ') }}@if (! empty($b['card']['experience_band'])). {{ $b['card']['experience_band'] }} of experience.@endif</p>
    @elseif (filled($b['excerpt'] ?? null))
        <p class="fl-pane__excerpt">{{ \Illuminate\Support\Str::limit($b['excerpt'], 220) }}</p>
    @endif
    @if ($aside && ! empty($aside['price']))
        <p class="fl-pane__price"><x-pulse.price :amount="$aside['price']['amount']" :currency="$aside['price']['currency']" :unit="$aside['price']['unit']" /></p>
    @elseif ($aside && ! empty($aside['text']))
        <p class="fl-pane__price pk-mono">{{ $aside['text'] }}</p>
    @endif

    @if ($meter->toHtml() !== '')
        <div class="fl-pane__meter">{{ $meter }}</div>
    @elseif (! empty($b['nearby_only']))
        <p class="fl-why fl-why--quiet">Nearby, no other reason yet.</p>
    @endif

    <div class="fl-pane__acts">
        @if (! empty($b['knock']))
            <x-pulse.button variant="primary" wire:click="$dispatch('pulse-knock', { spec: {{ \Illuminate\Support\Js::from($b['knock']) }} })" data-act="knock">{{ $b['knock']['label'] }}</x-pulse.button>
        @endif
        @if ($b['saved'] ?? false)
            <x-pulse.button variant="quiet" icon="bookmark" wire:click="unsave({{ $sid }})" aria-pressed="true" data-act="save">Saved</x-pulse.button>
        @else
            <x-pulse.button variant="quiet" icon="bookmark" wire:click="save({{ $sid }})" aria-pressed="false" data-act="save">Save</x-pulse.button>
        @endif
        <div class="fl-menu" x-data="{ open: false }" x-on:keydown.escape.stop="open = false" x-on:click.outside="open = false">
            <x-pulse.button variant="ghost" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="true" data-act="dismiss">Not for me</x-pulse.button>
            <div class="fl-menu__panel" x-show="open" x-cloak role="group" aria-label="Why not?">
                <p class="fl-small">Why? You can skip this.</p>
                <button type="button" wire:click="dismiss({{ $sid }})">Just remove it</button>
                <button type="button" wire:click="dismiss({{ $sid }}, 'not_for_me')">Not my kind of thing</button>
                <button type="button" wire:click="dismiss({{ $sid }}, 'seen_it')">Seen it</button>
                <button type="button" wire:click="dismiss({{ $sid }}, 'wrong_place')">Wrong place</button>
                <button type="button" wire:click="dismiss({{ $sid }}, 'not_now')">Not now</button>
            </div>
        </div>
        <a class="fl-pane__open" href="{{ $b['url'] ?? route('signals.show', $sid) }}">Open</a>
    </div>
    @if (! empty($b['knock_note']))
        <p class="fl-small fl-knocknote">
            @if (! empty($b['knock_href']) && $b['knock_href'] !== '#')<a href="{{ $b['knock_href'] }}">{{ $b['knock_note'] }}</a>@else{{ $b['knock_note'] }}@endif
        </p>
    @endif
</aside>
