<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['title' => $title ?: 'Dot.Pulse: say what you want', 'description' => $description, 'csrf' => false])
</head>
<body>
    <a class="skip-link" href="#main">Skip to the content</a>
    <header class="sh-mast">
        <x-pulse.brand :href="url('/')" />
        <div class="sh-mast__tools">
            <button type="button" class="sh-iconbtn sh-theme" data-pk-theme aria-pressed="false" aria-label="Switch theme">
                <x-pulse.icon name="moon" class="sh-theme__moon" />
                <x-pulse.icon name="sun" class="sh-theme__sun" />
            </button>
            @auth
                <x-pulse.button variant="signal" size="sm" :href="pulse_route_first(['floor', 'dashboard'])">Open the Floor</x-pulse.button>
            @else
                @if (Route::has('login'))
                    <x-pulse.button variant="signal" size="sm" :href="route('login')">Sign in</x-pulse.button>
                @endif
            @endauth
        </div>
    </header>
    <main id="main" tabindex="-1">
        {{ $slot }}
    </main>
    <footer class="lp-foot">
        <div class="pub-wrap">
            <span>Dot.Pulse is part of the Dot ecosystem.</span>
            @if (Route::has('terms.show'))<a href="{{ route('terms.show') }}">Terms of service</a>@endif
            @if (Route::has('policy.show'))<a href="{{ route('policy.show') }}">Privacy policy</a>@endif
            <a class="lp-foot__right" href="{{ pulse_infodot_url() }}">InfoDot</a>
        </div>
    </footer>
    <x-pulse.toasts />
</body>
</html>
