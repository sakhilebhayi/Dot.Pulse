{{--
    pulse/beat
    The card on the Floor. One thing, said plainly: its kind (a mono word), a heading that states it, who it comes from with their
    honest verification, the sync meter when there is a match, and two actions (Knock and Save). No like counts, no follower counts.

    Props: kind (offer need role talent ask event news collab business), title, href (the beat's own page), time (string, optional),
           place (string, optional), remote (bool), open (bool, shows the opened state)
    Slots: default            the body text (an excerpt)
           from               who it comes from (avatar + name + verification pill). Compose with <x-pulse.avatar> and <x-pulse.pill>
           meter              usually <x-pulse.sync-meter :reasons="$beat->reasons" />; leave empty when there is no match
           actions            the Knock and Save controls (x-pulse.button size="sm")
           aside              small right-aligned note in the actions row (a price, a close date)
--}}
@props(['kind' => 'ask', 'title', 'href' => null, 'time' => null, 'place' => null, 'remote' => false, 'open' => false])
<article {{ $attributes->merge(['class' => 'pk-beat'.($open ? ' pk-beat--open' : '')]) }}>
    <div class="pk-beat__top">
        <x-pulse.kind-word :kind="$kind" />
        @if ($place || $remote)<x-pulse.place-label :name="$place" :remote="$remote" />@endif
        @if ($time)<span class="pk-beat__time pk-mono">{{ $time }}</span>@endif
    </div>
    <h3 class="pk-beat__title">@if ($href)<a href="{{ $href }}">{{ $title }}</a>@else{{ $title }}@endif</h3>
    @if (trim((string) $slot) !== '')<div class="pk-beat__body">{{ $slot }}</div>@endif
    @if (isset($from) && trim((string) $from) !== '')<div class="pk-beat__from">{{ $from }}</div>@endif
    @if (isset($meter) && trim((string) $meter) !== '')<div class="pk-beat__meter">{{ $meter }}</div>@endif
    @if (isset($actions) || isset($aside))
        <div class="pk-beat__actions">
            @isset($actions){{ $actions }}@endisset
            @isset($aside)<span class="pk-beat__aside">{{ $aside }}</span>@endisset
        </div>
    @endif
</article>
