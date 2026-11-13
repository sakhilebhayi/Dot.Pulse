{{--
    pulse/arrival
    The top of the Floor for someone arriving (from InfoDot, or on any later visit): the Dot.Pulse logo, what this place
    is in one line, a welcome from the person's own name and company, the one thing to do first, and the way back to
    InfoDot. It sits above the round and never replaces it. Everything shown comes from Floor\Support\Arrival.

    The logo is the original file (public/images/logo.png), unaltered. It was drawn for a light ground, so it sits on
    a white plate in both themes.
--}}
@php
    $user = auth()->user();
    $arrival = app(\App\Livewire\Pulse\Floor\Support\Arrival::class);
    $first = $arrival->firstName($user);
    $company = $arrival->company($user);
    $action = $arrival->action($user);
    $infodot = $arrival->infodot();
@endphp
<section {{ $attributes->merge(['class' => 'fl-arrive']) }} aria-labelledby="fl-arrive-h" data-floor-arrival>
    <img class="fl-arrive__logo" src="{{ asset(\App\Livewire\Pulse\Floor\Support\Arrival::LOGO) }}" alt="Dot.Pulse" width="1181" height="886" decoding="async">
    <div class="fl-arrive__say">
        <h1 id="fl-arrive-h" class="fl-arrive__h">Introductions that say why.</h1>
        <p class="fl-arrive__lead">
            <span data-arrival-welcome>Welcome{{ $first !== '' ? ', '.$first : '' }}.@if ($company) You are here for <strong>{{ $company }}</strong>.@endif</span>
            Say what you want and Dot.Pulse introduces the businesses and people that fit, each with its reasons. Nobody reaches you without stating theirs.
        </p>
        <div class="fl-arrive__act">
            <x-pulse.button variant="signal" class="fl-arrive__go" :href="$action['href']" data-primary-action>{{ $action['label'] }}</x-pulse.button>
            <nav class="fl-arrive__back" aria-label="Dot ecosystem">
                <a href="{{ $infodot['home'] }}">InfoDot</a>
                <a href="{{ $infodot['platforms'] }}">Platforms</a>
            </nav>
        </div>
    </div>
</section>
