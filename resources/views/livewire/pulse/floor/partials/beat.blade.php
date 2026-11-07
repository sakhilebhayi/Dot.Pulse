{{--
    One beat, drawn from the array BeatPresenter makes. Shared by the Floor, search and saved so a beat looks and
    behaves the same wherever it turns up.

    Needs: $b (the beat array), $mode ('floor' | 'search' | 'saved'), optional $why (list of sentences from search).
    The host component supplies save, unsave, dismiss and removeReason (see Concerns\ActsOnBeats).
--}}
@php
    $anon = (bool) ($b['anonymous'] ?? false);
    $entity = $b['from']['entity'] ?? null;
    $logo = $entity && ! empty($entity['logo_media_id']) ? pulse_route('pulse.media', ['mediaFile' => $entity['logo_media_id'], 'size' => 'thumb']) : null;
    $logo = $logo === '#' ? null : $logo;
    $isDoor = ($b['kind'] ?? '') === 'door';
    $reasons = $b['reasons'] ?? [];
    $meter = \App\Livewire\Pulse\Floor\Support\MeterHtml::render($reasons, $b['match_id'] ?? null, $mode === 'floor' && ! empty($b['match_id']));
    $sid = (int) $b['signal_id'];
    $aside = $b['aside'] ?? null;
@endphp
<x-pulse.beat
    :kind="$b['kind']"
    :title="$b['title']"
    :href="$b['url'] ?? route('signals.show', $sid)"
    :time="$b['when'] ?? null"
    :place="$b['place']['label'] ?? null"
    wire:key="beat-{{ $sid }}"
    data-beat="{{ $sid }}"
    tabindex="-1"
    class="fl-beat"
>
    @if ($anon)
        <span class="fl-card-line">{{ collect($b['card']['skills'] ?? [])->take(6)->implode(', ') }}</span>
        @if (! empty($b['card']['experience_band']))
            <span class="fl-card-line fl-card-line--muted">{{ $b['card']['experience_band'] }} of experience</span>
        @endif
    @elseif (filled($b['excerpt'] ?? null))
        {{ \Illuminate\Support\Str::limit($b['excerpt'], 220) }}
    @endif

    <x-slot name="from">
        @if ($anon)
            <x-pulse.avatar name="" />
            <span class="pk-beat__from-text">
                <span class="pk-beat__from-name">Anonymous professional</span>
                <span class="fl-small">They choose whether to say who they are.</span>
            </span>
        @else
            <x-pulse.avatar :name="$b['from']['name'] ?? ''" :src="$logo ?? ($b['from']['avatar'] ?? null)" :entity="(bool) $entity" />
            <span class="pk-beat__from-text">
                <span class="pk-beat__from-name">{{ $b['from']['name'] ?? 'Someone on Pulse' }}</span>
                @if (! empty($b['verification']))
                    <span><x-pulse.pill :tone="$b['verification']['tone']">{{ $b['verification']['label'] }}</x-pulse.pill></span>
                @endif
            </span>
        @endif
    </x-slot>

    @if (! empty($why))
        <x-slot name="meter">
            @if ($meter->toHtml() !== '')
                {{ $meter }}
            @endif
            <p class="fl-why">{{ implode(' ', $why) }}</p>
        </x-slot>
    @elseif ($meter->toHtml() !== '')
        <x-slot name="meter">{{ $meter }}</x-slot>
    @endif

    <x-slot name="actions">
        @if (! empty($b['knock']))
            <x-pulse.button size="sm" variant="signal" wire:click="$dispatch('pulse-knock', { spec: {{ \Illuminate\Support\Js::from($b['knock']) }} })" data-act="knock">{{ $b['knock']['label'] }}</x-pulse.button>
        @elseif (in_array($b['kind'], ['ask', 'event', 'news'], true))
            <x-pulse.button size="sm" variant="primary" :href="route('signals.show', $sid)">{{ $b['kind'] === 'ask' ? 'Open the question' : 'Open' }}</x-pulse.button>
        @endif

        @if ($b['saved'] ?? false)
            <x-pulse.button size="sm" variant="quiet" icon="bookmark" wire:click="unsave({{ $sid }})" aria-pressed="true" data-act="save">Saved</x-pulse.button>
        @else
            <x-pulse.button size="sm" variant="quiet" icon="bookmark" wire:click="save({{ $sid }})" aria-pressed="false" data-act="save">Save</x-pulse.button>
        @endif

        @if ($mode !== 'saved')
            <div class="fl-menu" x-data="{ open: false }" x-on:keydown.escape.stop="open = false" x-on:click.outside="open = false">
                <x-pulse.button size="sm" variant="ghost" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="true" data-act="dismiss">Not for me</x-pulse.button>
                <div class="fl-menu__panel" x-show="open" x-cloak role="group" aria-label="Why not?">
                    <p class="fl-small">Why? You can skip this.</p>
                    <button type="button" wire:click="dismiss({{ $sid }})">Just remove it</button>
                    <button type="button" wire:click="dismiss({{ $sid }}, 'not_for_me')">Not my kind of thing</button>
                    <button type="button" wire:click="dismiss({{ $sid }}, 'seen_it')">Seen it</button>
                    <button type="button" wire:click="dismiss({{ $sid }}, 'wrong_place')">Wrong place</button>
                    <button type="button" wire:click="dismiss({{ $sid }}, 'not_now')">Not now</button>
                </div>
            </div>
        @endif

        @if (! empty($b['knock_note']))
            <span class="fl-small fl-knocknote">
                @if (! empty($b['knock_href']) && $b['knock_href'] !== '#')
                    <a href="{{ $b['knock_href'] }}">{{ $b['knock_note'] }}</a>
                @else
                    {{ $b['knock_note'] }}
                @endif
            </span>
        @endif
    </x-slot>

    @if ($aside && ! empty($aside['price']))
        <x-slot name="aside"><x-pulse.price :amount="$aside['price']['amount']" :currency="$aside['price']['currency']" :unit="$aside['price']['unit']" /></x-slot>
    @elseif ($aside && ! empty($aside['text']))
        <x-slot name="aside"><span class="pk-mono">{{ $aside['text'] }}</span></x-slot>
    @endif
</x-pulse.beat>
