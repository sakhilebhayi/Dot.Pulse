@php
    $user = auth()->user();
    $routeName = optional(request()->route())->getName();
    $floorRoutes = ['floor' => 'all', 'trade' => 'trade', 'work' => 'work', 'local' => 'local', 'learn' => 'learn'];
    $showDial = $dial ?? array_key_exists((string) $routeName, $floorRoutes);
    $selectedMode = $floorRoutes[$routeName] ?? 'all';
    $isFloor = $width === 'floor';
    $hasContext = isset($context) && trim((string) $context) !== '';
    $pageTitle = $title ? $title.' | Dot.Pulse' : 'Dot.Pulse';

    // Rail: the five verbs. Hrefs fall back to '#' until the screen behind them is built.
    $verbs = [
        ['key' => 'floor', 'label' => 'Floor', 'icon' => 'floor', 'href' => pulse_route_first(['floor', 'dashboard']), 'match' => ['floor', 'dashboard', 'search', 'saved', 'posts.*', 'communities.*', 'needs.*', 'offers.*', 'roles.*', 'entities.show']],
        ['key' => 'trade', 'label' => 'Trade', 'icon' => 'trade', 'href' => pulse_route('trade'), 'match' => ['trade']],
        ['key' => 'work', 'label' => 'Work', 'icon' => 'work', 'href' => pulse_route('work'), 'match' => ['work']],
        ['key' => 'lines', 'label' => 'Lines', 'icon' => 'lines', 'href' => pulse_route('lines'), 'match' => ['lines', 'lines.*'], 'badge' => 'lines'],
        ['key' => 'you', 'label' => 'You', 'icon' => 'you', 'href' => pulse_route_first(['me', 'profile.show']), 'match' => ['me', 'me.*', 'profile.*', 'teams.*', 'api-tokens.*', 'users.show', 'entities.manage*', 'entities.create', 'door']],
    ];
    $more = array_values(array_filter([
        ['label' => 'Search', 'icon' => 'search', 'href' => pulse_route('search'), 'match' => ['search'], 'show' => Route::has('search')],
        ['label' => 'Saved', 'icon' => 'bookmark', 'href' => pulse_route('saved'), 'match' => ['saved'], 'show' => Route::has('saved')],
        ['label' => 'Open door', 'icon' => 'door', 'href' => pulse_route('door'), 'match' => ['door'], 'show' => Route::has('door')],
        ['label' => 'Moderation', 'icon' => 'shield', 'href' => pulse_route('moderation'), 'match' => ['moderation'], 'show' => $user && Route::has('moderation') && $user->canAccessModeration()],
        ['label' => 'Operator', 'icon' => 'chart', 'href' => pulse_route('operator'), 'match' => ['operator'], 'show' => $user && Route::has('operator') && $user->canAccessModeration()],
    ], fn ($i) => $i['show']));
    $bellRoute = Route::has('me.notifications');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['title' => $pageTitle, 'description' => $description, 'csrf' => true, 'legacyIcons' => true])
    @livewireStyles
</head>
{{-- data-community-channels: the private communities this user belongs to, one realtime channel each (resources/js/pulse-realtime.js). --}}
<body data-user-id="{{ auth()->id() }}" data-pulse-check="{{ url('/api/pulse/pulse-check') }}" data-community-channels="{{ auth()->check() ? auth()->user()->joinedCommunities()->where('visibility', '!=', 'public')->pluck('communities.id')->implode(',') : '' }}">
    <a class="skip-link" href="#main">Skip to the floor</a>
    <x-banner />

    <header class="sh-mast{{ $showDial ? ' sh-mast--dial' : '' }}" id="shell-topbar">
        <x-pulse.brand />

        @if ($showDial)
            <div class="sh-mast__dial"><x-pulse.mode-dial :selected="$selectedMode" /></div>
        @else
            <div class="sh-mast__page">{{ $title }}</div>
        @endif

        <div class="sh-mast__tools">
            @if (Route::has('search'))
                <form class="sh-search" role="search" action="{{ route('search') }}" method="get">
                    <label for="mast-q" class="sr-only">Search the floor</label>
                    <x-pulse.icon name="search" :size="16" />
                    <input id="mast-q" type="search" name="q" placeholder="Search the floor" autocomplete="off" value="{{ request()->routeIs('search') ? request('q') : '' }}">
                </form>
                <a class="sh-iconbtn sh-searchicon" href="{{ route('search') }}" aria-label="Search"><x-pulse.icon name="search" /></a>
            @endif

            @if ($bellRoute)
                <a class="sh-iconbtn" href="{{ route('me.notifications') }}" aria-label="Notifications" data-label="Notifications">
                    <x-pulse.icon name="bell" />
                    <span class="sh-badge" data-pulse-badge="notifications" data-label="Notifications" hidden>0</span>
                </a>
            @else
                @auth
                    <livewire:pulse.notification-bell />
                @endauth
            @endif

            <button type="button" class="sh-iconbtn sh-theme sh-hide-phone" data-pk-theme aria-pressed="false" aria-label="Switch theme">
                <x-pulse.icon name="moon" class="sh-theme__moon" />
                <x-pulse.icon name="sun" class="sh-theme__sun" />
            </button>

            @if ($isFloor && $hasContext)
                <button type="button" class="sh-iconbtn sh-context-open" data-pk-context-open aria-controls="sh-context" aria-expanded="false" aria-label="Filters and context">
                    <x-pulse.icon name="menu" />
                </button>
            @endif

            @auth
                <div class="sh-menuwrap">
                    <button type="button" class="sh-iconbtn" data-pk-toggle="user-menu" aria-expanded="false" aria-controls="user-menu">
                        <span class="sr-only">Your account, {{ $user->name }}</span>
                        <x-pulse.avatar :name="$user->name" size="sm" style="background: var(--c-signal); color: var(--c-on-signal); border-color: var(--c-ink-fixed)" />
                    </button>
                    <div class="sh-menu" id="user-menu" hidden>
                        <div class="sh-menu__who">
                            <strong>{{ $user->name }}</strong>
                            <span>{{ $user->currentTeam->name ?? 'Personal' }}</span>
                        </div>
                        <a href="{{ route('profile.show') }}">Profile and settings</a>
                        @if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && $user->currentTeam)
                            <div class="sh-menu__label">Team</div>
                            <a href="{{ route('teams.show', $user->currentTeam->id) }}">Team settings</a>
                            @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                                <a href="{{ route('teams.create') }}">Create new team</a>
                            @endcan
                            @if ($user->allTeams()->count() > 1)
                                <div class="sh-menu__label">Switch team</div>
                                @foreach ($user->allTeams() as $team)
                                    <form method="POST" action="{{ route('current-team.update') }}">
                                        @method('PUT')
                                        @csrf
                                        <input type="hidden" name="team_id" value="{{ $team->id }}">
                                        <button type="submit" class="sh-menu__item" @if ($user->isCurrentTeam($team)) aria-current="true" @endif>
                                            <span>{{ $team->name }}</span>
                                            @if ($user->isCurrentTeam($team))<x-pulse.icon name="check" class="sh-check" :size="16" />@endif
                                        </button>
                                    </form>
                                @endforeach
                            @endif
                        @endif
                        <div class="sh-menu__label">Appearance</div>
                        <button type="button" class="sh-menu__item" data-pk-theme aria-pressed="false">Switch theme</button>
                        <div class="sh-menu__label">Session</div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="sh-menu__item">Log out</button>
                        </form>
                    </div>
                </div>
            @endauth
        </div>
    </header>

    <div class="sh-body content-wrap">
        <nav class="sh-rail" aria-label="Main">
            <ul class="sh-rail__verbs">
                @foreach ($verbs as $verb)
                    @php $current = pulse_route_is(...$verb['match']); @endphp
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
                            <li><a class="sh-verb" href="{{ $item['href'] }}" @if (pulse_route_is(...$item['match'])) aria-current="page" @endif><x-pulse.icon :name="$item['icon']" :size="18" /><span>{{ $item['label'] }}</span></a></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="sh-rail__foot">Part of the Dot ecosystem. Signed in with InfoDot.</p>
        </nav>

        <main id="main" tabindex="-1" class="sh-stage {{ $isFloor ? 'sh-stage--floor' : 'sh-stage--wide' }}{{ $isFloor && $hasContext ? ' has-context' : '' }}">
            @if ($isFloor)
                <div class="sh-floor">
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

    <nav class="sh-bar" aria-label="Main, phone">
        @foreach ($verbs as $verb)
            <a href="{{ $verb['href'] }}" @if (pulse_route_is(...$verb['match'])) aria-current="page" @endif>
                <x-pulse.icon :name="$verb['icon']" :size="22" />
                <span>{{ $verb['label'] }}</span>
                @if (! empty($verb['badge']))<span class="sh-badge" data-pulse-badge="{{ $verb['badge'] }}" data-label="{{ $verb['label'] }}" hidden>0</span>@endif
            </a>
        @endforeach
    </nav>
    <x-pulse.button variant="signal" class="sh-fab pk-btn--float" data-pk-dialog="compose" icon="plus" aria-label="Compose">Compose</x-pulse.button>

    <x-pulse.drawer id="compose" title="Say what you want" side="bottom" description="Pick the thing that is true. Pulse does the introducing.">
        <div class="pk-stack" style="gap: 8px">
            @foreach ([
                ['I need something', 'Say what you need and where. Sellers who fit will see it.', 'needs.create'],
                ['I offer something', 'Set up a business page, then publish offers and roles.', 'entities.create'],
                ['I want to open a door', 'Let strangers knock once, for a day or a week, about one thing.', 'door'],
                ['I have a question or news', 'Ask the community or share an update.', 'dashboard'],
            ] as [$name, $text, $route])
                <a class="pk-row" href="{{ pulse_route($route) }}" style="text-decoration: none; color: inherit">
                    <span class="pk-row__main"><span class="pk-row__title">{{ $name }}</span><span class="pk-row__meta">{{ $text }}</span></span>
                </a>
            @endforeach
        </div>
    </x-pulse.drawer>

    @stack('modals')
    <x-pulse.toasts />

    @livewireScripts
</body>
</html>
