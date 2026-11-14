{{--
    pulse/arrival
    The top of the Floor for someone arriving (from InfoDot, or on any later visit): the Dot.Pulse logo, what this place
    is in one line, a welcome from the person's own name and company, the one thing to do first, and the way back to
    InfoDot. It sits above the round and never replaces it. The headline is a true statement about this round (Round::headline). Everything shown comes from Floor\Support\Arrival.

    The logo is the original file (public/images/logo.png), unaltered. It was drawn for a light ground, so it sits on
    a white plate in both themes.
--}}
@props(['headline' => 'The floor.', 'lead' => 'Each row says why it fits.'])
@php
    $user = auth()->user();
    $arrival = app(\App\Livewire\Pulse\Floor\Support\Arrival::class);
    $first = $arrival->firstName($user);
    $company = $arrival->company($user);
    $action = $arrival->action($user);
    $infodot = $arrival->infodot();
@endphp
<section {{ $attributes->merge(['class' => 'fl-hello']) }} aria-labelledby="fl-hello-h" data-floor-arrival>
    <span class="fl-hello__plate"><img class="fl-hello__logo" src="{{ asset(\App\Livewire\Pulse\Floor\Support\Arrival::LOGO) }}" alt="Dot.Pulse" width="1181" height="886" decoding="async"></span>
    <div class="fl-hello__say">
        <h1 id="fl-hello-h" class="fl-hello__h">{{ $headline }}</h1>
        <p class="fl-hello__lead">
            <span data-arrival-welcome>Welcome{{ $first !== '' ? ', '.$first : '' }}.@if ($company) You are here for <strong>{{ $company }}</strong>.@endif</span>
            {{ $lead }}
        </p>
    </div>
    <div class="fl-hello__act">
        <x-pulse.button variant="signal" class="fl-hello__go" :href="$action['href']" data-primary-action>{{ $action['label'] }}</x-pulse.button>
        <nav class="fl-hello__back" aria-label="Dot ecosystem">
            <a href="{{ $infodot['home'] }}">InfoDot</a>
            <a href="{{ $infodot['platforms'] }}">Platforms</a>
        </nav>
    </div>
    {{-- Decoration drawn from the app's own sync meter: two lines that meet and part. It carries no data. --}}
    <svg class="fl-hello__band" viewBox="0 0 1200 24" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <path class="fl-hello__you" d="M0 18C150 18 150 6 300 6S450 18 600 18 750 6 900 6 1050 18 1200 18"/>
        <path class="fl-hello__them" d="M0 6C150 6 150 18 300 18S450 6 600 6 750 18 900 18 1050 6 1200 6"/>
    </svg>
</section>
