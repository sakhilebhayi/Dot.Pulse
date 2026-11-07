{{--
    Error shell. Must work when the database, the session or the build are gone: no auth(), no csrf, no Livewire.
    Sections: code, title, message, actions (optional).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['title' => trim($__env->yieldContent('title')).' | Dot.Pulse', 'description' => null, 'csrf' => false])
    <meta name="robots" content="noindex">
</head>
<body>
    <header class="sh-mast">
        <x-pulse.brand href="/" />
    </header>
    <main id="main" class="er-main">
        <div class="pub-wrap">
            <span class="er-code pk-kind">Error @yield('code')</span>
            <h1>@yield('title')</h1>
            <p>@yield('message')</p>
            <div class="er-actions">
                @hasSection('actions')
                    @yield('actions')
                @else
                    <a class="pk-btn pk-btn--signal" href="/">Back to Pulse</a>
                @endif
            </div>
            <div class="er-miss" aria-hidden="true">
                <svg viewBox="0 0 760 120" focusable="false">
                    <path class="er-miss__you" pathLength="1" d="M0 96 C170 96 250 76 332 62 L352 58"/>
                    <path class="er-miss__them" pathLength="1" d="M760 24 C590 24 510 44 428 58 L408 62"/>
                    <circle class="er-miss__ghost" cx="380" cy="60" r="9"/>
                </svg>
            </div>
        </div>
    </main>
</body>
</html>
