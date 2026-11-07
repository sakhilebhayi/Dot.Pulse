{{--
    Document head shared by every layout: meta, self-hosted fonts (preloaded), the theme script that runs before first paint,
    and the built assets. Variables: $title (string), $description (string|null), $csrf (bool), $legacyIcons (bool).
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
@if ($csrf ?? true)<meta name="csrf-token" content="{{ csrf_token() }}">@endif
<title>{{ $title }}</title>
@if (! empty($description))<meta name="description" content="{{ $description }}">@endif
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#1f3cff">
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="alternate icon" href="{{ asset('favicon.ico') }}">
<link rel="preload" href="{{ asset('fonts/archivo-latin-wdth-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ asset('fonts/jetbrains-mono-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    (function () {
        document.documentElement.classList.add('js');
        try {
            var q = location.search.match(/[?&]theme=(light|dark)\b/);
            var t = q ? q[1] : localStorage.getItem('pulse-theme');
            if (t === 'light' || t === 'dark') { document.documentElement.setAttribute('data-theme', t); }
        } catch (e) { /* storage blocked: the OS preference decides */ }
        // ?theme=light|dark previews a theme for this page only (QA and screenshots).
    })();
</script>
@if (! empty($legacyIcons))
    {{-- Three pre-R-41 views still use Material Symbols ligatures (lock, groups, notifications, add, close). Subset only; remove with the last of them. --}}
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0&icon_names=add,close,groups,lock,notifications&display=block">
@endif
@php
    try {
        $__assets = (string) app(\Illuminate\Foundation\Vite::class)(['resources/css/app.css', 'resources/js/app.js']);
    } catch (\Throwable $e) {
        $__assets = '';
    }
@endphp
{!! $__assets !!}
