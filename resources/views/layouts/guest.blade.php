<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['title' => ($title ? $title.' | ' : '').'Dot.Pulse', 'description' => $description, 'csrf' => true])
    @livewireStyles
</head>
<body>
    <a class="skip-link" href="#main">Skip to the content</a>
    <header class="sh-mast">
        <x-pulse.brand />
        <div class="sh-mast__tools">
            <button type="button" class="sh-iconbtn sh-theme" data-pk-theme aria-pressed="false" aria-label="Switch theme">
                <x-pulse.icon name="moon" class="sh-theme__moon" />
                <x-pulse.icon name="sun" class="sh-theme__sun" />
            </button>
        </div>
    </header>
    <main id="main" tabindex="-1">
        {{ $slot }}
    </main>
    <x-pulse.toasts />
    @livewireScripts
</body>
</html>
