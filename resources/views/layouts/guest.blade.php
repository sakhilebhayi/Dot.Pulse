<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto" data-dh-theme-key="pulse-theme">
<head>
    @include('layouts.partials.head', ['title' => ($title ? $title.' | ' : '').'Dot.Pulse', 'description' => $description, 'csrf' => true])
    @livewireStyles
</head>
<body>
    <a class="skip-link" href="#main">Skip to the content</a>
    <x-dot.header brand-href="{{ url('/') }}" brand-label="Dot.Pulse home" skip="#main" :guest="\App\Support\ShellAccount::guest()">
        <x-slot:brand><x-pulse.logo :height="40" class="sh-brand__logo" /></x-slot:brand>
        <x-slot:tools>
            <button type="button" class="dh-icon-btn sh-theme" data-pk-theme aria-pressed="false" aria-label="Switch theme">
                <x-pulse.icon name="moon" class="sh-theme__moon" />
                <x-pulse.icon name="sun" class="sh-theme__sun" />
            </button>
        </x-slot:tools>
    </x-dot.header>
    <main id="main" tabindex="-1">
        {{ $slot }}
    </main>
    <x-pulse.toasts />
    <script src="{{ asset('dot-ui/js/dot-nav.js') }}" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" defer></script>
    @livewireScripts
</body>
</html>
