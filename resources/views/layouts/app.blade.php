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
        .sidebar { position:fixed; left:0; top:0; width:260px; height:100vh; height:100dvh; background:#0d0d10; border-right:1px solid rgba(255,255,255,0.06); display:flex; flex-direction:column; z-index:40; overflow:hidden; }
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
        .dot-badge { white-space:nowrap; display:inline-flex; align-items:center; padding:2px 8px; border-radius:100px; font-size:11px; font-weight:600; }
        .dot-badge-accent { background:rgba(241,198,46,0.12); color:#f1c62e; }
        .dot-badge-success { background:rgba(34,197,94,0.12); color:#4ade80; }
        .dot-badge-danger { background:rgba(239,68,68,0.12); color:#f87171; }
        .dot-badge-neutral { background:rgba(255,255,255,0.06); color:#71717a; }
        .dot-avatar { border-radius:50%; background:rgba(241,198,46,0.16); border:1px solid rgba(241,198,46,0.28); display:flex; align-items:center; justify-content:center; font-weight:700; color:#f1c62e; font-family:'Syne',sans-serif; flex-shrink:0; text-decoration:none; }
        .dot-row { border:1px solid rgba(255,255,255,0.07); border-radius:10px; transition:border-color .15s,background .15s; }
        .dot-row:hover { border-color:rgba(255,255,255,0.13); background:rgba(255,255,255,0.015); }
        select.dot-input option { background:#1a1a1f; }
        textarea.dot-input { resize:none; line-height:1.5; }

        /* ---------------------------------------------------------------
           Responsive shell. Breakpoint: 860px (max-width:859.98px = phone/
           tablet drawer mode, min-width:860px = fixed sidebar, unchanged).
           Hooks: #shell-menu-button, #shell-drawer, #shell-backdrop,
           .topbar, .table-scroll. Behaviour lives in the script at the end
           of <body>; see README "Responsive shell".
           --------------------------------------------------------------- */
        .shell-menu-btn, .shell-close-btn, .shell-backdrop { display:none; }
        .shell-menu-btn, .shell-close-btn { align-items:center; gap:6px; min-height:44px; padding:0 12px 0 10px; border-radius:8px; border:1px solid rgba(255,255,255,0.1); background:rgba(255,255,255,0.05); color:#d4d4d8; font-family:'Inter',sans-serif; font-size:13px; font-weight:600; cursor:pointer; flex-shrink:0; }
        .shell-menu-btn:hover, .shell-close-btn:hover { background:rgba(255,255,255,0.1); color:#f4f4f5; }
        .shell-menu-btn .material-symbols-rounded, .shell-close-btn .material-symbols-rounded { font-size:20px; }
        .shell-menu-btn:focus-visible, .shell-close-btn:focus-visible, .sidebar a:focus-visible, .sidebar button:focus-visible { outline:2px solid #f1c62e; outline-offset:2px; }
        .topbar-title { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .topbar-title h2 { margin:0; font:inherit; color:inherit; }
        .sidebar-brand { position:relative; }
        .sidebar-account { flex-shrink:0; max-height:45%; overflow-y:auto; padding:6px 10px 10px; border-top:1px solid rgba(255,255,255,0.06); margin-top:6px; }
        .sidebar-account .nav-section-label { padding-top:10px; }
        .sidebar-account form { margin:0; }
        .nav-button { width:100%; background:none; border:none; cursor:pointer; text-align:left; font-family:'Inter',sans-serif; }
        .nav-item .nav-check { margin-left:auto; font-size:16px; color:#f1c62e; }
        .nav-item .nav-label { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0; }
        .table-scroll { max-width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
        main { min-width:0; overflow-wrap:anywhere; }

        /* Page content */
        .pulse-page { padding:2rem 2.5rem 3rem; max-width:1100px; }
        .kpi-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; margin-bottom:2rem; }
        .kpi-card { padding:1.25rem 1.5rem; }
        .kpi-label { font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:0.09em; color:#52525b; margin-bottom:0.75rem; }
        .kpi-value { font-size:2rem; font-weight:600; color:var(--accent); }
        .feed-grid { display:grid; grid-template-columns:1fr 260px; gap:1.25rem; margin-top:1.25rem; align-items:start; }
        .feed-grid > * { min-width:0; }
        .section-head { display:flex; align-items:center; justify-content:space-between; gap:0.75rem; flex-wrap:wrap; margin-bottom:1rem; }
        .section-head h3 { margin:0; }
        .form-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; }
        .meta-row { display:flex; align-items:center; flex-wrap:wrap; gap:4px 8px; margin-bottom:2px; }
        .action-row { display:flex; align-items:center; flex-wrap:wrap; gap:4px 16px; }
        .inline-form-row { display:flex; align-items:center; flex-wrap:wrap; gap:10px; }
        .inline-form-row > input { flex:1 1 14rem; min-width:0; }
        .reply-thread { margin-left:32px; margin-top:12px; display:flex; flex-direction:column; gap:12px; border-left:1px solid rgba(255,255,255,0.07); padding-left:16px; }
        .reply-form { margin-left:32px; margin-top:12px; }
        .search-field { width:200px; }
        .bell-panel { position:absolute; top:38px; right:0; width:320px; max-height:400px; overflow-y:auto; z-index:50; padding:0.75rem; }
        .popover-form { position:absolute; left:0; top:100%; z-index:30; margin-top:6px; width:300px; max-width:calc(100vw - 2rem); padding:1rem; display:flex; flex-direction:column; gap:10px; box-shadow:0 12px 32px rgba(0,0,0,0.5); }
        .modal-card { width:360px; max-width:calc(100vw - 2rem); max-height:70vh; overflow-y:auto; padding:1.25rem; }
        .profile-head { display:flex; align-items:flex-start; gap:16px; flex-wrap:wrap; }
        .profile-head-main { flex:1 1 220px; min-width:0; }
        .stat-row { display:flex; align-items:center; flex-wrap:wrap; gap:6px 18px; margin-top:14px; font-size:12px; color:#71717a; }

        @media (max-width:1099.98px) {
            .feed-grid { grid-template-columns:1fr; }
        }

        @media (max-width:859.98px) {
            .shell-menu-btn { display:inline-flex; }
            .topbar { left:0; padding:0 10px; gap:8px; }
            .topbar-title { flex:1 1 0; }
            .topbar-btn { width:44px; height:44px; }
            .topbar-btn .material-symbols-rounded { font-size:22px; }
            .content-wrap { margin-left:0; }

            /* Sidebar becomes an off-canvas drawer. visibility (not just the
               transform) takes it out of the tab order and the accessibility
               tree while closed; the delay lets the slide-out finish first. */
            .sidebar { width:min(300px,86vw); transform:translateX(-100%); visibility:hidden; transition:transform .22s ease, visibility 0s linear .22s; z-index:50; box-shadow:none; }
            .sidebar.is-open { transform:none; visibility:visible; transition:transform .22s ease, visibility 0s; box-shadow:0 0 40px rgba(0,0,0,0.6); }
            .shell-close-btn { display:inline-flex; margin-left:auto; justify-content:center; padding:0; width:44px; }
            .shell-backdrop { display:block; position:fixed; inset:0; z-index:45; background:rgba(0,0,0,0.62); opacity:0; visibility:hidden; transition:opacity .22s ease, visibility 0s linear .22s; }
            .shell-backdrop.is-open { opacity:1; visibility:visible; transition:opacity .22s ease, visibility 0s; }
            html.shell-locked, html.shell-locked body { overflow:hidden; }
            .nav-item { min-height:44px; padding:8px 12px; font-size:14px; }
            .nav-item:hover { transform:none; }

            .pulse-page { padding:1.25rem 1rem 2.5rem; }
            .dot-input { font-size:16px !important; }
            .search-field { width:100%; }
            .tap { min-height:44px; }
            button.tap:not(.dot-btn), a.tap:not(.dot-btn) { display:inline-flex; align-items:center; padding-left:8px; padding-right:8px; }
            .bell-panel { position:fixed; top:58px; left:8px; right:8px; width:auto; max-height:calc(100dvh - 72px); }
            .popover-form { position:fixed; left:1rem; right:1rem; top:auto; bottom:1rem; width:auto; max-width:none; margin:0; z-index:60; }
            .reply-thread { margin-left:12px; padding-left:12px; }
            .reply-form { margin-left:12px; }
            #pulse-notice { right:12px !important; left:12px; bottom:12px !important; max-width:none !important; }
        }

        @media (max-width:559.98px) {
            .topbar-team { display:none; }
            .kpi-grid { grid-template-columns:1fr; gap:0.5rem; margin-bottom:1.25rem; }
            .kpi-card { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:0.9rem 1.1rem; }
            .kpi-label { margin-bottom:0; }
            .kpi-value { font-size:1.5rem; }
            .form-grid-2 { grid-template-columns:1fr; }
            .dot-card { border-radius:10px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .sidebar, .shell-backdrop { transition:none !important; }
            .live-dot { animation:none; }
            .nav-item { transition:none; }
        }
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

    {{-- Responsive shell (see README "Responsive shell"). At >=860px #shell-drawer is the
         fixed sidebar; below it, it is an off-canvas drawer opened from the top bar. --}}
    <div id="shell-backdrop" class="shell-backdrop" aria-hidden="true"></div>

    <aside id="shell-drawer" class="sidebar" aria-label="Dot.Pulse sidebar">
        <div class="sidebar-brand">
            <img src="{{ asset('images/logo.png') }}" alt="Dot.Pulse" class="dot-logo brand-logo">
            <div class="brand-status">
                <div class="live-dot"></div>
                <span class="brand-subtitle">Community Intelligence</span>
            </div>
            <button type="button" id="shell-close-button" class="shell-close-btn" aria-label="Close menu">
                <span class="material-symbols-rounded" aria-hidden="true">close</span>
            </button>
        </div>

        <div class="sidebar-divider"></div>

        <nav class="sidebar-nav" aria-label="Main">
            <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>
                <span class="material-symbols-rounded nav-icon" aria-hidden="true">dashboard</span>
                Dashboard
            </a>

            @auth
                @if(auth()->user()->canAccessModeration())
                    <a href="{{ route('moderation') }}" class="nav-item {{ request()->routeIs('moderation') ? 'active' : '' }}" @if(request()->routeIs('moderation')) aria-current="page" @endif>
                        <span class="material-symbols-rounded nav-icon" aria-hidden="true">gavel</span>
                        Moderation
                    </a>
                @endif
            @endauth

            <div class="sidebar-divider" style="margin:10px 0;"></div>
            <a href="{{ route('profile.show') }}" class="nav-item {{ request()->routeIs('profile.show') ? 'active' : '' }}" @if(request()->routeIs('profile.show')) aria-current="page" @endif>
                <span class="material-symbols-rounded nav-icon" aria-hidden="true">manage_accounts</span>
                Profile & Settings
            </a>
        </nav>

        @auth
        {{-- Team switcher and sign-out. These used to live only in the stock Jetstream
             navigation-menu, which rendered underneath the fixed top bar where nothing in it
             could be reached; they are part of the sidebar/drawer now. --}}
        <div class="sidebar-account" id="shell-account">
            @if(Laravel\Jetstream\Jetstream::hasTeamFeatures() && Auth::user()->currentTeam)
                <div class="nav-section-label">Team</div>
                <a href="{{ route('teams.show', Auth::user()->currentTeam->id) }}" class="nav-item {{ request()->routeIs('teams.show') ? 'active' : '' }}">
                    <span class="material-symbols-rounded nav-icon" aria-hidden="true">groups</span>
                    <span class="nav-label">Team settings</span>
                </a>
                @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                    <a href="{{ route('teams.create') }}" class="nav-item {{ request()->routeIs('teams.create') ? 'active' : '' }}">
                        <span class="material-symbols-rounded nav-icon" aria-hidden="true">add</span>
                        <span class="nav-label">Create new team</span>
                    </a>
                @endcan
                @if(Auth::user()->allTeams()->count() > 1)
                    <div class="nav-section-label">Switch team</div>
                    @foreach(Auth::user()->allTeams() as $team)
                        <form method="POST" action="{{ route('current-team.update') }}">
                            @method('PUT')
                            @csrf
                            <input type="hidden" name="team_id" value="{{ $team->id }}">
                            <button type="submit" class="nav-item nav-button" @if(Auth::user()->isCurrentTeam($team)) aria-current="true" @endif>
                                <span class="material-symbols-rounded nav-icon" aria-hidden="true">{{ Auth::user()->isCurrentTeam($team) ? 'radio_button_checked' : 'radio_button_unchecked' }}</span>
                                <span class="nav-label">{{ $team->name }}</span>
                            </button>
                        </form>
                    @endforeach
                @endif
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="nav-item nav-button">
                    <span class="material-symbols-rounded nav-icon" aria-hidden="true">logout</span>
                    <span class="nav-label">Log out</span>
                </button>
            </form>
        </div>

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

    <header class="topbar" id="shell-topbar">
        <button type="button" id="shell-menu-button" class="shell-menu-btn" aria-expanded="false" aria-controls="shell-drawer">
            <span class="material-symbols-rounded" aria-hidden="true">menu</span>
            <span>Menu</span>
        </button>
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
        <a href="{{ route('profile.show') }}" class="topbar-btn" title="Profile" aria-label="Profile">
            <span class="material-symbols-rounded" aria-hidden="true">account_circle</span>
        </a>
    </header>

    <div class="content-wrap">
        <main>{{ $slot }}</main>
    </div>

    @stack('modals')

    {{-- Rate-limited actions (App\Support\PulseThrottle) answer HTTP 429.
         Livewire would show its raw error overlay; show a short notice instead. --}}
    <div id="pulse-notice" role="status" aria-live="polite" class="dot-card" hidden
         style="position:fixed;right:20px;bottom:20px;z-index:80;max-width:320px;padding:0.75rem 1rem;font-size:12.5px;color:#f4f4f5;box-shadow:0 12px 32px rgba(0,0,0,0.5);"></div>

    @livewireScripts

    {{-- Drawer behaviour for widths under 860px. Plain script on purpose: Alpine here is
         Livewire's bundled instance and this shell must not depend on a component. While the
         drawer is open the top bar and page content are made inert (no focus, no clicks, hidden
         from assistive tech), the page cannot scroll, Escape or the backdrop closes it, and focus
         goes back to the Menu button. Above 860px nothing here has any visible effect. --}}
    <script>
        (function () {
            var button = document.getElementById('shell-menu-button');
            var drawer = document.getElementById('shell-drawer');
            var backdrop = document.getElementById('shell-backdrop');
            var closeButton = document.getElementById('shell-close-button');
            if (! button || ! drawer || ! backdrop) { return; }

            var behind = [document.getElementById('shell-topbar'), document.querySelector('.content-wrap')];
            var desktop = window.matchMedia('(min-width: 860px)');
            var isOpen = false;

            function setOpen(next, moveFocus) {
                if (next === isOpen) { return; }
                isOpen = next;

                drawer.classList.toggle('is-open', next);
                backdrop.classList.toggle('is-open', next);
                document.documentElement.classList.toggle('shell-locked', next);
                button.setAttribute('aria-expanded', next ? 'true' : 'false');
                behind.forEach(function (el) { if (el) { el.inert = next; } });

                if (! moveFocus) { return; }

                if (next) {
                    void drawer.offsetWidth;
                    (closeButton || drawer).focus();
                } else {
                    button.focus();
                }
            }

            button.addEventListener('click', function () { setOpen(! isOpen, true); });
            if (closeButton) { closeButton.addEventListener('click', function () { setOpen(false, true); }); }
            backdrop.addEventListener('click', function () { setOpen(false, true); });
            drawer.addEventListener('click', function (event) {
                if (event.target.closest && event.target.closest('a[href]')) { setOpen(false, true); }
            });
            document.addEventListener('keydown', function (event) {
                if (isOpen && event.key === 'Escape') { event.preventDefault(); setOpen(false, true); }
            });
            var onChange = function () { if (desktop.matches) { setOpen(false, false); } };
            if (desktop.addEventListener) { desktop.addEventListener('change', onChange); } else { desktop.addListener(onChange); }
            window.addEventListener('pageshow', function (event) { if (event.persisted) { setOpen(false, false); } });
        })();
    </script>
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
