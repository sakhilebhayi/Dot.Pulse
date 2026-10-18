<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dot.Pulse</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    {{-- darkMode:'class' + class="dark" on <html> above: this layout wraps the
         stock Jetstream form/action-section components (profile & settings, team
         management), which ship dark: classes that otherwise only activate on
         prefers-color-scheme:dark — this app never toggles a scheme, it's always
         dark, so those need to be forced rather than left to the visitor's OS. --}}
    <script>tailwind.config = { darkMode: 'class', corePlugins: { preflight: false } }</script>
    <style>
        :root { --accent: #f1c62e; --accent-rgb: 241,198,46; }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin:0; background:#09090b; color:#f4f4f5; font-family:'Inter',system-ui,sans-serif; font-size:14px; line-height:1.5; }
        .material-symbols-rounded { font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; line-height:1; user-select:none; }
        [x-cloak] { display:none!important; }

        /* Sidebar */
        .sidebar { position:fixed; left:0; top:0; width:260px; height:100vh; background:#0d0d10; border-right:1px solid rgba(255,255,255,0.06); display:flex; flex-direction:column; z-index:40; overflow:hidden; }
        .sidebar::before { content:''; position:absolute; top:-80px; left:-80px; width:320px; height:320px; background:radial-gradient(circle, rgba(241,198,46,0.1) 0%, transparent 65%); pointer-events:none; }

        .sidebar-brand { padding:20px 18px 14px; display:flex; align-items:center; gap:11px; flex-shrink:0; }
        /* The logo's wordmark/chevron are navy (#08354f), which measures ~1.5:1 contrast
           against every dark surface in this app (well under WCAG's 3:1 floor for
           graphics) -- a soft rim + glow lifts the shape off dark backgrounds without
           altering the actual asset's colors. */
        .dot-logo { filter: drop-shadow(0 0 1px rgba(255,255,255,0.7)) drop-shadow(0 0 2px rgba(255,255,255,0.4)) drop-shadow(0 0 8px rgba(241,198,46,0.35)); }
        .brand-logo { height:40px; width:auto; flex-shrink:0; }
        .brand-status { display:flex; align-items:center; gap:5px; }
        .live-dot { width:6px; height:6px; border-radius:50%; background:#f1c62e; flex-shrink:0; animation:live-pulse 2.8s ease-in-out infinite; }
        @keyframes live-pulse { 0%,100% { opacity:1; box-shadow:0 0 0 0 rgba(241,198,46,0.45); } 60% { opacity:.6; box-shadow:0 0 0 5px rgba(241,198,46,0); } }
        .brand-subtitle { font-size:10px; font-weight:500; color:#3f3f46; text-transform:uppercase; letter-spacing:0.09em; }

        .sidebar-divider { height:1px; background:rgba(255,255,255,0.06); margin:4px 14px 8px; }
        .sidebar-nav { padding:0 10px; flex:1; overflow-y:auto; scrollbar-width:none; }
        .sidebar-nav::-webkit-scrollbar { display:none; }
        .nav-section-label { font-size:10px; font-weight:600; color:#3f3f46; text-transform:uppercase; letter-spacing:0.1em; padding:14px 8px 5px; }
        .nav-item { display:flex; align-items:center; gap:9px; padding:7.5px 10px; border-radius:8px; font-size:13px; font-weight:500; color:#71717a; text-decoration:none; transition:background .13s,color .13s,transform .13s; margin-bottom:1px; }
        .nav-item:hover { background:rgba(255,255,255,0.05); color:#d4d4d8; transform:translateX(1px); }
        .nav-item.active { background:rgba(241,198,46,0.1); color:#f1c62e; font-weight:600; }
        .nav-icon { font-size:17px; width:20px; text-align:center; flex-shrink:0; }

        .sidebar-footer { padding:10px 14px 14px; border-top:1px solid rgba(255,255,255,0.06); flex-shrink:0; }
        .user-row { display:flex; align-items:center; gap:9px; padding:8px 6px; border-radius:8px; }
        .user-avatar { width:28px; height:28px; border-radius:50%; background:rgba(241,198,46,0.18); border:1px solid rgba(241,198,46,0.28); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; color:#f1c62e; flex-shrink:0; font-family:'Syne',sans-serif; }
        .user-name { font-size:12px; font-weight:600; color:#d4d4d8; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .user-team { font-size:10px; color:#52525b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

        /* Topbar */
        .topbar { position:fixed; top:0; left:260px; right:0; height:54px; background:rgba(9,9,11,0.85); backdrop-filter:blur(14px); -webkit-backdrop-filter:blur(14px); border-bottom:1px solid rgba(255,255,255,0.06); display:flex; align-items:center; padding:0 22px; z-index:30; gap:12px; }
        .topbar-title { font-family:'Syne',sans-serif; font-size:14px; font-weight:700; color:#f4f4f5; flex:1; }
        .topbar-team { font-size:11px; color:#52525b; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.07); border-radius:6px; padding:3px 8px; font-weight:500; white-space:nowrap; }
        .topbar-btn { width:30px; height:30px; border-radius:7px; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.04); display:flex; align-items:center; justify-content:center; color:#71717a; cursor:pointer; transition:background .13s,color .13s; text-decoration:none; flex-shrink:0; }
        .topbar-btn:hover { background:rgba(255,255,255,0.09); color:#d4d4d8; }
        .topbar-btn .material-symbols-rounded { font-size:17px; }

        /* Content */
        .content-wrap { margin-left:260px; padding-top:54px; min-height:100vh; }

        /* Shared UI tokens */
        .dot-card { background:#141416; border:1px solid rgba(255,255,255,0.07); border-radius:12px; }
        .dot-card:hover { border-color:rgba(255,255,255,0.11); }
        .metric-val { font-family:'JetBrains Mono',monospace; font-weight:500; letter-spacing:-0.02em; font-variant-numeric:tabular-nums; }
        .dot-input { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-radius:8px; color:#f4f4f5; font-family:'Inter',sans-serif; font-size:13px; padding:8px 12px; width:100%; transition:border-color .15s,box-shadow .15s; outline:none; }
        .dot-input:focus { border-color:rgba(241,198,46,0.45); box-shadow:0 0 0 3px rgba(241,198,46,0.07); }
        .dot-input::placeholder { color:#3f3f46; }
        .dot-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; transition:all .14s; border:none; text-decoration:none; font-family:'Inter',sans-serif; }
        .dot-btn-primary { background:#f1c62e; color:#08354f; }
        .dot-btn-primary:hover { filter:brightness(1.1); }
        .dot-btn-ghost { background:rgba(255,255,255,0.06); color:#a1a1aa; border:1px solid rgba(255,255,255,0.08); }
        .dot-btn-ghost:hover { background:rgba(255,255,255,0.1); color:#f4f4f5; }
        .dot-badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:100px; font-size:11px; font-weight:600; }
        .dot-badge-accent { background:rgba(241,198,46,0.12); color:#f1c62e; }
        .dot-badge-success { background:rgba(34,197,94,0.12); color:#4ade80; }
        .dot-badge-danger { background:rgba(239,68,68,0.12); color:#f87171; }
        .dot-badge-neutral { background:rgba(255,255,255,0.06); color:#71717a; }
        .dot-avatar { border-radius:50%; background:rgba(241,198,46,0.16); border:1px solid rgba(241,198,46,0.28); display:flex; align-items:center; justify-content:center; font-weight:700; color:#f1c62e; font-family:'Syne',sans-serif; flex-shrink:0; text-decoration:none; }
        .dot-row { border:1px solid rgba(255,255,255,0.07); border-radius:10px; transition:border-color .15s,background .15s; }
        .dot-row:hover { border-color:rgba(255,255,255,0.13); background:rgba(255,255,255,0.015); }
        select.dot-input option { background:#1a1a1f; }
        textarea.dot-input { resize:none; line-height:1.5; }
    </style>
    @livewireStyles
    {{-- No manual Alpine script here: @livewireScripts below already bundles
         and auto-starts Alpine (Livewire 3 default, config/livewire.php is
         unpublished so inject_assets is still true). Loading it a second
         time via CDN caused "Detected multiple instances of Alpine running"
         and broke x-show/x-data on Jetstream's dropdown and delete-account
         modal, which bind to Livewire's own Alpine instance. --}}
    {{-- JS-only: this layout's CSS pipeline is the Tailwind CDN script
         above, not resources/css/app.css -- @vite here only loads the
         Reverb/Echo bundle (resources/js/app.js), it doesn't touch
         styling. --}}
    @vite(['resources/js/app.js'])
</head>
{{-- data-community-channels: the private communities this user belongs to,
     one realtime channel each (resources/js/pulse-realtime.js). Public
     communities need none: their posts ride the shared private feed channel. --}}
<body data-user-id="{{ auth()->id() }}" data-community-channels="{{ auth()->check() ? auth()->user()->joinedCommunities()->where('visibility', '!=', 'public')->pluck('communities.id')->implode(',') : '' }}">
    <x-banner />

    <aside class="sidebar">
        <div class="sidebar-brand">
            <img src="{{ asset('images/logo.png') }}" alt="Dot.Pulse" class="dot-logo brand-logo">
            <div class="brand-status">
                <div class="live-dot"></div>
                <span class="brand-subtitle">Community Intelligence</span>
            </div>
        </div>

        <div class="sidebar-divider"></div>

        <nav class="sidebar-nav">
            <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">dashboard</span>
                Dashboard
            </a>

            @auth
                @if(auth()->user()->canAccessModeration())
                    <a href="{{ route('moderation') }}" class="nav-item {{ request()->routeIs('moderation') ? 'active' : '' }}">
                        <span class="material-symbols-rounded nav-icon">gavel</span>
                        Moderation
                    </a>
                @endif
            @endauth

            <div class="sidebar-divider" style="margin:10px 0;"></div>
            <a href="{{ route('profile.show') }}" class="nav-item {{ request()->routeIs('profile.show') ? 'active' : '' }}">
                <span class="material-symbols-rounded nav-icon">manage_accounts</span>
                Profile & Settings
            </a>
        </nav>

        @auth
        <div class="sidebar-footer">
            <div class="user-row">
                <div class="user-avatar">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</div>
                <div style="min-width:0;flex:1;">
                    <div class="user-name">{{ Auth::user()->name }}</div>
                    <div class="user-team">{{ Auth::user()->currentTeam->name ?? 'Personal' }}</div>
                </div>
            </div>
        </div>
        @endauth
    </aside>

    <header class="topbar">
        <div class="topbar-title">
            @isset($header){{ $header }}@else Dot.Pulse
            @endisset
        </div>
        @auth
        <span class="topbar-team">{{ Auth::user()->currentTeam->name ?? 'Personal' }}</span>
        @endauth
        @auth
            <livewire:pulse.notification-bell />
        @endauth
        <a href="{{ route('profile.show') }}" class="topbar-btn" title="Profile">
            <span class="material-symbols-rounded">account_circle</span>
        </a>
    </header>

    @livewire('navigation-menu')

    <div class="content-wrap">
        <main>{{ $slot }}</main>
    </div>

    @stack('modals')

    {{-- Rate-limited actions (App\Support\PulseThrottle) answer HTTP 429.
         Livewire would show its raw error overlay; show a short notice instead. --}}
    <div id="pulse-notice" role="status" aria-live="polite" class="dot-card" hidden
         style="position:fixed;right:20px;bottom:20px;z-index:80;max-width:320px;padding:0.75rem 1rem;font-size:12.5px;color:#f4f4f5;box-shadow:0 12px 32px rgba(0,0,0,0.5);"></div>

    @livewireScripts
    <script>
        document.addEventListener('livewire:init', () => {
            let hideTimer;

            Livewire.hook('request', ({ respond, fail }) => {
                let retryAfter = null;

                respond(({ response }) => { retryAfter = response?.headers?.get('Retry-After') ?? null; });

                fail(({ status, preventDefault }) => {
                    if (status !== 429) { return; }

                    preventDefault();

                    const notice = document.getElementById('pulse-notice');
                    notice.textContent = retryAfter
                        ? `You are doing that too quickly. Try again in ${retryAfter} seconds.`
                        : 'You are doing that too quickly. Try again in a moment.';
                    notice.hidden = false;

                    clearTimeout(hideTimer);
                    hideTimer = setTimeout(() => { notice.hidden = true; }, 6000);
                });
            });
        });
    </script>
</body>
</html>
