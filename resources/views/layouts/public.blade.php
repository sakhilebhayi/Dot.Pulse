<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto" data-dh-theme-key="pulse-theme">
<head>
    @include('layouts.partials.head', ['title' => $title ?: 'Dot.Pulse: say what you want', 'description' => $description, 'csrf' => false])
</head>
<body>
    <a class="skip-link" href="#main">Skip to the content</a>
    <x-dot.header brand-href="{{ url('/') }}" brand-label="Dot.Pulse home" skip="#main"
        :account="auth()->check() ? \App\Support\ShellAccount::for(auth()->user(), pulse_my_businesses()) : null"
        :guest="auth()->check() ? null : \App\Support\ShellAccount::guest()">
        <x-slot:brand><x-pulse.logo :height="40" class="sh-brand__logo" /></x-slot:brand>
        <x-slot:tools>
            @guest
                <button type="button" class="dh-icon-btn sh-theme" data-pk-theme aria-pressed="false" aria-label="Switch theme">
                    <x-pulse.icon name="moon" class="sh-theme__moon" />
                    <x-pulse.icon name="sun" class="sh-theme__sun" />
                </button>
            @endguest
            @auth
                <x-pulse.button variant="signal" size="sm" class="sh-open-floor" :href="pulse_route_first(['floor', 'dashboard'])">Open the Floor</x-pulse.button>
            @endauth
        </x-slot:tools>
    </x-dot.header>
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
    <script src="{{ asset('dot-ui/js/dot-nav.js') }}" defer></script>
</body>
</html>
