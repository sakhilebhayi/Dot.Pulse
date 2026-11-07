{{--
    pulse/kind-word
    The mono word that names what a beat is. The word is the meaning; colour never carries it.
    Props: kind (offer need role talent ask event news collab business door, or any word)
--}}
@props(['kind'])
@php
    $words = ['offer' => 'Offer', 'need' => 'Need', 'role' => 'Role', 'talent' => 'Talent', 'ask' => 'Ask', 'event' => 'Event', 'news' => 'News', 'collab' => 'Collab', 'business' => 'Business', 'door' => 'Door'];
    $word = $words[strtolower((string) $kind)] ?? (string) $kind;
@endphp
<span {{ $attributes->merge(['class' => 'pk-kind']) }}>{{ $word }}</span>
