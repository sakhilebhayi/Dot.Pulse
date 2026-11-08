{{--
    One beat, drawn from the array BeatPresenter makes. Shared by the Floor, search and saved so a beat looks and
    behaves the same wherever it turns up.

    Needs: $b (the beat array), $mode ('floor' | 'search' | 'saved'), optional $why (list of sentences from search),
    optional $lead (the top one to three beats of a round: the full strand), optional $first (the very first, which also says
    "Best match for you"), optional $compact (every other beat: a small strand beside one line of up to three reason
    chips, the full thread on expand) and optional $quiet (a beat seen earlier: lighter, same actions).
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
    $lead = ($lead ?? false) && $meter->toHtml() !== '';
    $compact = ($compact ?? false) && $meter->toHtml() !== '';
    $quiet = $quiet ?? false;
    $first = ($first ?? false) && $lead;
    $mini = $compact ? \App\Livewire\Pulse\Floor\Support\MeterHtml::render($reasons, null, false, 'Them', true) : null;
    $nearbyOnly = (bool) ($b['nearby_only'] ?? false);
    $row = ($row ?? false) || $mode === 'search';
    $chips = collect($reasons)->pluck('text')->filter()->values();
@endphp
<x-pulse.beat
    :kind="$b['kind']"
    :title="$b['title']"
    :href="$b['url'] ?? route('signals.show', $sid)"
    :time="$b['when'] ?? null"
    :place="$b['place']['label'] ?? null"
    :near="(bool) ($b['near'] ?? false)"
    wire:key="beat-{{ $sid }}"
    data-beat="{{ $sid }}"
    tabindex="-1"
    class="fl-beat{{ $quiet ? ' fl-beat--quiet' : '' }}{{ $row ? ' fl-beat--row' : '' }}{{ $compact ? ' fl-beat--compact' : '' }}{{ $lead ? ' fl-beat--lead' : '' }}"
    x-data="{ thread: false }"
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
        @if ($row && ($b['kind'] ?? '') === 'business')
            {{-- a business result is the business: no second line naming it --}}
        @elseif ($row)
            <span class="pk-beat__from-text fl-rowfrom">
                <span class="pk-beat__from-name">{{ $anon ? 'Anonymous professional' : ($b['from']['name'] ?? 'Someone on Pulse') }}</span>
                @if (! $anon && ! empty($b['verification']))<span class="fl-small">{{ $b['verification']['label'] }}</span>@endif
            </span>
        @elseif ($anon)
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
            @if ($meter->toHtml() !== '' && ! $row)
                {{ $meter }}
            @endif
            <p class="fl-why">{{ implode(' ', $why) }}</p>
        </x-slot>
    @elseif ($compact || $lead)
        {{-- Compact: a small strand beside one line of up to three reason chips, the thread on expand. The lead card shows the whole thread on a wide screen and the chips on a phone. --}}
        <x-slot name="meter">
            @if ($first)<p class="fl-lead-note">Best match for you, {{ \App\Support\Moment::plural($chips->count(), 'reason') }}.</p>@endif
            <div @class(['fl-lead-phone' => $lead, 'fl-compact' => $compact])>
                @if ($mini)<span class="fl-mini">{{ $mini }}</span>@endif
                <ul class="fl-chips" aria-label="Why this fits">
                    @foreach ($chips->take(3) as $text)
                        <li class="fl-chip">{{ $text }}</li>
                    @endforeach
                </ul>
                @if ($chips->count() > 3)<span class="fl-small fl-more-reasons">+{{ $chips->count() - 3 }}</span>@endif
                <button type="button" class="fl-link fl-thread-toggle" x-on:click="thread = ! thread" x-bind:aria-expanded="thread" aria-controls="thread-{{ $sid }}">
                    <span x-show="! thread">Show how it fits</span><span x-show="thread" x-cloak>Hide how it fits</span>
                </button>
            </div>
            <div id="thread-{{ $sid }}" class="fl-thread" x-show="thread" x-cloak>{{ $meter }}</div>
        </x-slot>
    @elseif ($meter->toHtml() !== '')
        <x-slot name="meter">{{ $meter }}</x-slot>
    @elseif ($nearbyOnly)
        <x-slot name="meter"><p class="fl-why fl-why--quiet">Nearby, no other reason yet.</p></x-slot>
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
