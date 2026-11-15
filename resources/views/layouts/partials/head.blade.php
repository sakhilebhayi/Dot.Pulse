{{--
    Document head shared by every layout: meta, self-hosted Roboto (preloaded), the theme script that runs before first paint,
    the shared Dot UI styles and the built assets. Variables: $title (string), $description (string|null), $csrf (bool).
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
<link rel="preload" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ asset('fonts/jetbrains-mono-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
{{-- First paint: the stored light/dark/system choice (key pulse-theme, set on <html data-dh-theme-key>) is applied by dot-ui's external, synchronous boot script. --}}
<script src="{{ asset('dot-ui/js/dot-theme-boot.min.js') }}" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    (function () {
        document.documentElement.classList.add('js');
        // ?theme=light|dark previews a theme for this page only (QA and screenshots).
        var q = location.search.match(/[?&]theme=(light|dark)\b/);
        if (q) { document.documentElement.setAttribute('data-theme', q[1]); }
    })();
</script>
{{-- Shared Dot UI 0.6.1, served from this site's own public/dot-ui (no CDN): Roboto and the type scale, tokens, the search pill, the switcher and the header in ONE minified stylesheet. The host token mapping lives in resources/css/pulse/shell.css. --}}
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-ui.min.css') }}">
@php
    try {
        $__assets = (string) app(\Illuminate\Foundation\Vite::class)(['resources/css/app.css', 'resources/js/app.js']);
    } catch (\Throwable $e) {
        $__assets = '';
    }
@endphp
{!! $__assets !!}
