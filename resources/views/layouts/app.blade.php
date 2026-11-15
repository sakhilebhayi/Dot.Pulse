@php
    $user = auth()->user();
    $routeName = optional(request()->route())->getName();
    $floorRoutes = ['floor' => 'all', 'trade' => 'trade', 'work' => 'work', 'local' => 'local', 'learn' => 'learn'];
    $showDial = $dial ?? array_key_exists((string) $routeName, $floorRoutes);
    $selectedMode = $floorRoutes[$routeName] ?? 'all';
    $isFloor = in_array($width, ['floor', 'desk'], true);
    $isDesk = $width === 'desk';
    $focus = $focus ?? false;
    $hasContext = isset($context) && trim((string) $context) !== '';
    $pageTitle = $title ? $title.' | Dot.Pulse' : 'Dot.Pulse';

    $businesses = pulse_my_businesses();
    $manageSlug = pulse_route_is('entities.manage*') ? optional(request()->route('entity'))->slug : null;

    // Rail: the five verbs. Hrefs fall back to '#' until the screen behind them is built.
    // Exactly one item is active. The specific places (Search, Saved, Open door, a business you run) are checked first and
    // claim their own pages; the verbs keep the rest. On the phone bar only the five verbs exist, so a specific place lights
    // the verb it belongs to ('parent').
    $verbs = [
        ['key' => 'floor', 'label' => 'Floor', 'icon' => 'floor', 'href' => pulse_route_first(['floor', 'dashboard']), 'match' => ['floor', 'local', 'learn', 'dashboard', 'signals.*']],
        ['key' => 'trade', 'label' => 'Trade', 'icon' => 'trade', 'href' => pulse_route('trade'), 'match' => ['trade']],
        ['key' => 'work', 'label' => 'Work', 'icon' => 'work', 'href' => pulse_route('work'), 'match' => ['work']],
        ['key' => 'lines', 'label' => 'Lines', 'icon' => 'lines', 'href' => pulse_route('lines'), 'match' => ['lines', 'lines.*'], 'badge' => 'lines'],
        ['key' => 'you', 'label' => 'You', 'icon' => 'you', 'href' => pulse_route_first(['me', 'profile.show']), 'match' => ['me', 'me.*', 'profile.*', 'teams.*', 'api-tokens.*', 'users.show', 'entities.create']],
    ];
    $more = array_values(array_filter([
        ['key' => 'search', 'parent' => 'floor', 'label' => 'Search', 'icon' => 'search', 'href' => pulse_route('search'), 'match' => ['search'], 'show' => Route::has('search')],
        ['key' => 'saved', 'parent' => 'floor', 'label' => 'Saved', 'icon' => 'bookmark', 'href' => pulse_route('saved'), 'match' => ['saved'], 'show' => Route::has('saved')],
        ['key' => 'community', 'parent' => 'floor', 'label' => 'Community', 'icon' => 'lines', 'href' => pulse_route('community.feed'), 'match' => ['community.*', 'communities.*', 'posts.*'], 'show' => Route::has('community.feed')],
        ['key' => 'door', 'parent' => 'you', 'label' => 'Open door', 'icon' => 'door', 'href' => pulse_route('door'), 'match' => ['door'], 'show' => Route::has('door')],
        ['key' => 'moderation', 'parent' => 'floor', 'label' => 'Moderation', 'icon' => 'shield', 'href' => pulse_route('moderation'), 'match' => ['moderation'], 'show' => $user && Route::has('moderation') && $user->canAccessModeration()],
        ['key' => 'operator', 'parent' => 'floor', 'label' => 'Operator', 'icon' => 'chart', 'href' => pulse_route('operator'), 'match' => ['operator'], 'show' => $user && Route::has('operator') && $user->isPlatformModerator()],
    ], fn ($i) => $i['show']));
    $biz = array_map(fn ($b) => [
        'key' => 'biz:'.$b['slug'], 'parent' => 'you', 'label' => $b['name'], 'role' => $b['role'], 'icon' => 'work',
        'href' => pulse_route('entities.manage', ['entity' => $b['slug']]), 'slug' => $b['slug'],
    ], $businesses);

    $activeKey = null;
    foreach ($more as $item) {
        if (pulse_route_is(...$item['match'])) { $activeKey = $item['key']; break; }
    }
    if ($activeKey === null && $manageSlug !== null) {
        $activeKey = in_array($manageSlug, array_column($businesses, 'slug'), true) ? 'biz:'.$manageSlug : 'you';
    }
    // A page about one business, offer, role or need belongs to where the person came to it from: the business they run,
    // else the verb it is about (Trade for what is sold, Work for what is hired, You for their own need).
    if ($activeKey === null && pulse_route_is('entities.show', 'offers.show', 'roles.show')) {
        $seen = request()->route('entity');
        $seenSlug = is_object($seen) ? $seen->slug : (string) $seen;
        $activeKey = in_array($seenSlug, array_column($businesses, 'slug'), true)
            ? 'biz:'.$seenSlug
            : (pulse_route_is('roles.show') ? 'work' : (pulse_route_is('offers.show') || (is_object($seen) && $seen->is_seller) ? 'trade' : 'work'));
    }
    if ($activeKey === null && pulse_route_is('needs.*')) {
        $need = request()->route('need');
        $activeKey = pulse_route_is('needs.show') && is_object($need) && (int) $need->user_id !== (int) auth()->id() ? 'trade' : 'you';
    }
    if ($activeKey === null) {
        foreach ($verbs as $verb) {
            if (pulse_route_is(...$verb['match'])) { $activeKey = $verb['key']; break; }
        }
    }
    $activeParent = collect(array_merge($more, $biz))->firstWhere('key', $activeKey)['parent'] ?? $activeKey;
    // The phone bar has five verbs and no business entry, so a business page lights none of them.
    $phoneActive = str_starts_with((string) $activeKey, 'biz:') ? null : $activeParent;
    $bellRoute = Route::has('me.notifications');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto" data-dh-theme-key="pulse-theme">
<head>
    @include('layouts.partials.head', ['title' => $pageTitle, 'description' => $description, 'csrf' => true])
    @livewireStyles
</head>
{{-- data-community-channels: the private communities this user belongs to, one realtime channel each (resources/js/pulse-realtime.js). --}}
<body data-user-id="{{ auth()->id() }}" data-pulse-check="{{ url('/api/pulse/pulse-check') }}" data-community-channels="{{ auth()->check() ? auth()->user()->joinedCommunities()->where('visibility', '!=', 'public')->pluck('communities.id')->implode(',') : '' }}">
    <a class="skip-link" href="#main">Skip to the floor</a>
    <x-banner />

    {{-- The shared Dot header (dot-ui 0.6.0): one row, the search centred by the grid. The five verbs live in the rail (from 861px) and the
         phone bottom bar; the drawer below holds what the bar cannot reach (Search, Saved, Community, Open door, Moderation, Operator, your
         businesses). The header itself carries no primary nav, so nothing is listed twice. --}}
    <x-dot.header id="shell-topbar" brand-href="{{ pulse_route_first(['floor', 'dashboard']) }}" brand-label="Dot.Pulse home" skip="#main" drawer-title="Menu"
        :account="$user ? \App\Support\ShellAccount::for($user, $businesses) : null" :guest="$user ? null : \App\Support\ShellAccount::guest()">
        <x-slot:brand><x-pulse.logo :height="40" class="sh-brand__logo" /></x-slot:brand>

        @if (Route::has('search') && $user)
            <x-slot:search>
                <livewire:pulse.header-search :query="request()->routeIs('search') ? (string) request('q', '') : ''" />
            </x-slot:search>
        @endif

        <x-slot:tools>
            @auth
                <x-dot.switcher platform="pulse" :company="$user->currentTeam?->infodot_team_uuid" :company-name="$user->currentTeam?->name" logos="/dot-ui/img/platforms" />
            @endauth
            @if ($bellRoute)
                <x-dot.bell :href="route('me.notifications')" :count="0" :always="true" data-pulse-bell />
            @endif
            @unless ($focus)<button type="button" class="dh-icon-btn sh-compose-top" data-pk-dialog="compose" aria-label="Compose"><x-pulse.icon name="plus" /></button>@endunless
        </x-slot:tools>

        <x-slot:drawer>
            <nav class="dh-dnav" aria-label="More places">
                <ul>
                    @foreach ($more as $item)
                        <li><a class="dh-dnav__link" href="{{ $item['href'] }}" @if ($activeKey === $item['key']) aria-current="page" @endif><span class="dh-menu__label">{{ $item['label'] }}</span></a></li>
                    @endforeach
                    @foreach ($biz as $b)
                        <li><a class="dh-dnav__link" href="{{ $b['href'] }}" @if ($activeKey === $b['key']) aria-current="page" @endif><span class="dh-menu__label">{{ $b['label'] }}</span></a></li>
                    @endforeach
                </ul>
            </nav>
        </x-slot:drawer>
    </x-dot.header>

    <div class="sh-body content-wrap{{ $focus ? ' sh-body--focus' : '' }}">
        @unless ($focus)
        <nav class="sh-rail" aria-label="Main">
            <ul class="sh-rail__verbs">
                @foreach ($verbs as $verb)
                    @php $current = $activeParent === $verb['key'] && ! in_array($activeKey, array_column(array_merge($more, $biz), 'key'), true); @endphp
                    <li>
                        <a class="sh-verb" href="{{ $verb['href'] }}" @if ($current) aria-current="page" @endif>
                            <x-pulse.icon :name="$verb['icon']" />
                            <span>{{ $verb['label'] }}</span>
                            @if (! empty($verb['badge']))<span class="sh-badge sh-badge--static" data-pulse-badge="{{ $verb['badge'] }}" data-label="{{ $verb['label'] }}" hidden>0</span>@endif
                        </a>
                    </li>
                @endforeach
            </ul>

            <x-pulse.button variant="signal" data-pk-dialog="compose" icon="plus" block>Compose</x-pulse.button>

            @if (count($more))
                <div>
                    <div class="sh-rail__sub">More</div>
                    <ul class="sh-rail__more">
                        @foreach ($more as $item)
                            <li><a class="sh-verb" href="{{ $item['href'] }}" @if ($activeKey === $item['key']) aria-current="page" @endif><x-pulse.icon :name="$item['icon']" :size="18" /><span>{{ $item['label'] }}</span></a></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (count($biz))
                <div>
                    <div class="sh-rail__sub">{{ count($biz) === 1 ? 'Your business' : 'Your businesses' }}</div>
                    <ul class="sh-rail__more">
                        @foreach ($biz as $b)
                            <li><a class="sh-verb" href="{{ $b['href'] }}" @if ($activeKey === $b['key']) aria-current="page" @endif><x-pulse.icon name="{{ $b['icon'] }}" :size="18" /><span class="sh-verb__text">{{ $b['label'] }}</span></a></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="sh-rail__foot">Part of the Dot ecosystem. Signed in with InfoDot.</p>
        </nav>
        @endunless

        <main id="main" tabindex="-1" class="sh-stage {{ $isFloor ? 'sh-stage--floor' : 'sh-stage--wide' }}{{ $isDesk ? ' sh-stage--desk' : '' }}{{ $isFloor && $hasContext ? ' has-context' : '' }}">
            @if ($isFloor)
                <div class="sh-floor">
                    @if ($showDial || ($hasContext && $isFloor))
                        <div class="sh-floorbar">
                            @if ($showDial)<x-pulse.mode-dial :selected="$selectedMode" :page="true" />@endif
                            @if ($hasContext)
                                <button type="button" class="pk-btn pk-btn--quiet sh-context-open" data-pk-context-open aria-controls="sh-context" aria-expanded="false"><x-pulse.icon name="menu" :size="18" />Filters</button>
                            @endif
                        </div>
                    @endif
                    @isset($header)<header class="sh-pagehead">{{ $header }}</header>@endisset
                    {{ $slot }}
                </div>
                @if ($hasContext)
                    <aside class="sh-context" id="sh-context" aria-label="Filters and context">
                        <x-pulse.button variant="quiet" size="sm" class="sh-context__close" data-pk-context-close>Close</x-pulse.button>
                        {{ $context }}
                    </aside>
                @endif
            @else
                <div class="sh-wide">
                    @isset($header)<header class="sh-pagehead">{{ $header }}</header>@endisset
                    {{ $slot }}
                </div>
            @endif
        </main>
    </div>

    @unless ($focus)
    <nav class="sh-bar" aria-label="Main, phone">
        @foreach ($verbs as $verb)
            <a href="{{ $verb['href'] }}" @if ($phoneActive === $verb['key']) aria-current="page" @endif>
                <x-pulse.icon :name="$verb['icon']" :size="22" />
                <span>{{ $verb['label'] }}</span>
                @if (! empty($verb['badge']))<span class="sh-badge" data-pulse-badge="{{ $verb['badge'] }}" data-label="{{ $verb['label'] }}" hidden>0</span>@endif
            </a>
        @endforeach
    </nav>
    @endunless

    <x-pulse.drawer id="compose" title="Say what you want" side="bottom" description="Pick the thing that is true. Pulse does the introducing.">
        <div class="pk-stack" style="gap: 8px">
            @foreach ([
                ['I need something', 'Say what you need and where. Sellers who fit will see it.', 'needs.create', null],
                ['I offer something', 'Set up a business page, then publish offers and roles.', 'entities.create', null],
                ['I want to open a door', 'Let strangers knock once, for a day or a week, about one thing.', 'door', null],
                ['I have a question or news', 'Ask the community or share an update.', 'community.feed', '?compose=1#share'],
            ] as [$name, $text, $route, $anchor])
                <a class="pk-row" href="{{ pulse_route($route) }}{{ $anchor }}" style="text-decoration: none; color: inherit">
                    <span class="pk-row__main"><span class="pk-row__title">{{ $name }}</span><span class="pk-row__meta">{{ $text }}</span></span>
                </a>
            @endforeach
        </div>
    </x-pulse.drawer>

    @stack('modals')
    <x-pulse.toasts />

    @livewireScripts
    <script src="{{ asset('dot-ui/js/dot-ui-core.min.js') }}" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" defer></script>
</body>
</html>
