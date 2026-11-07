{{--
    pulse/row
    A line in a list: a conversation, a notification, a request. Leading avatar or icon, a title (optionally a link), a meta line,
    and an end slot for a time or an action. Unread rows get a yellow edge and the word "New" for assistive tech.

    Props: title, href (optional), meta (optional), unread (bool)
    Slots: default (extra body), lead (avatar), end (time or action)
--}}
@props(['title', 'href' => null, 'meta' => null, 'unread' => false])
<div {{ $attributes->merge(['class' => 'pk-row'.($unread ? ' pk-row--unread' : '')]) }}>
    @isset($lead){{ $lead }}@endisset
    <div class="pk-row__main">
        <div class="pk-row__title">@if($unread)<span class="sr-only">New: </span>@endif @if ($href)<a href="{{ $href }}">{{ $title }}</a>@else{{ $title }}@endif</div>
        @if ($meta)<div class="pk-row__meta">{{ $meta }}</div>@endif
        @if (trim((string) $slot) !== '')<div>{{ $slot }}</div>@endif
    </div>
    @isset($end)<div class="pk-row__end">{{ $end }}</div>@endisset
</div>
