{{--
    Document head shared by every layout: meta, self-hosted fonts (preloaded), the theme script that runs before first paint,
    and the built assets. Variables: $title (string), $description (string|null), $csrf (bool).
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
@if ($csrf ?? true)<meta name="csrf-token" content="{{ csrf_token() }}">@endif
<title>{{ $title }}</title>
@if (! empty($description))<meta name="description" content="{{ $description }}">@endif
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#1f3cff">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" href="{{ asset('favicon-32x32.png') }}" type="image/png" sizes="32x32">
<link rel="icon" href="{{ asset('favicon-16x16.png') }}" type="image/png" sizes="16x16">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
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
@php
    try {
        $__assets = (string) app(\Illuminate\Foundation\Vite::class)(['resources/css/app.css', 'resources/js/app.js']);
    } catch (\Throwable $e) {
        $__assets = '';
    }
@endphp
{!! $__assets !!}
