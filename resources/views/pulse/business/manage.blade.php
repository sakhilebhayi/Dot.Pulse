@php
    use App\Livewire\Pulse\Business\Support\EntityView;
    use App\Services\Pulse\Lines\Access;
    $user = auth()->user();
    $manageListings = in_array($myRole, ['owner', 'admin', 'editor'], true);
    $admin = in_array($myRole, ['owner', 'admin'], true);
    $tabs = ['overview' => ['label' => 'Overview', 'href' => route('entities.manage', ['entity' => $entity->slug])]];
    if ($entity->is_seller && $manageListings) { $tabs['offers'] = ['label' => 'Offers', 'href' => route('entities.manage.offers', ['entity' => $entity->slug])]; }
    if ($entity->is_employer && $manageListings) { $tabs['roles'] = ['label' => 'Roles', 'href' => route('entities.manage.roles', ['entity' => $entity->slug])]; }
    if ($admin) { $tabs['team'] = ['label' => 'Team', 'href' => route('entities.manage.team', ['entity' => $entity->slug])]; }
    if (Access::canRespondFor($user, $entity)) { $tabs['lines'] = ['label' => 'Lines', 'href' => route('entities.manage.lines', ['entity' => $entity->slug])]; }
    if ($admin) {
        $tabs['verify'] = ['label' => 'Verify', 'href' => route('entities.manage.verify', ['entity' => $entity->slug])];
        $tabs['page'] = ['label' => 'Page', 'href' => route('entities.manage.page', ['entity' => $entity->slug])];
    }
    $isLive = $entity->isLive();
@endphp
<x-app-layout :title="'Manage '.$entity->name" width="wide">
    <x-slot name="header">
        <div>
            <h1 class="bz-pagetitle">{{ $entity->name }}</h1>
            <p>
                Your role: {{ $myRole }}.
                @if ($isLive)<a href="{{ route('entities.show', ['entity' => $entity->slug]) }}">See the page as people see it</a>.@else Only your team can see this page right now.@endif
            </p>
        </div>
        <x-pulse.button variant="quiet" :href="route('entities.show', ['entity' => $entity->slug])">View page</x-pulse.button>
    </x-slot>

    <div class="bz bz-manage">
        @if (session('bz_notice'))
            <div class="bz-notice" role="status"><x-pulse.icon name="info" :size="18" /><p>{{ session('bz_notice') }}</p></div>
        @endif

        <x-pulse.tabs :tabs="$tabs" :active="$tab" label="Manage this page" class="bz-tabs" />

        <div class="bz-tabpanel">
            @switch($tab)
                @case('offers')
                    <livewire:pulse.business.offer-manager :entity="$entity" :key="'offers-'.$entity->id" />
                    @break
                @case('roles')
                    <livewire:pulse.business.role-manager :entity="$entity" :key="'roles-'.$entity->id" />
                    @break
                @case('team')
                    <livewire:pulse.business.team-manager :entity="$entity" :key="'team-'.$entity->id" />
                    @break
                @case('verify')
                    <livewire:pulse.business.verify-panel :entity="$entity" :key="'verify-'.$entity->id" />
                    @break
                @case('page')
                    <livewire:pulse.business.entity-form :entity="$entity" :key="'page-'.$entity->id" />
                    @break
                @case('lines')
                    @if (class_exists(\App\Livewire\Pulse\Lines\Inbox::class))
                        <livewire:pulse.lines.inbox :entity-id="$entity->id" :key="'lines-'.$entity->id" />
                    @else
                        <x-pulse.empty title="This page's lines" text="Everything people send to {{ $entity->name }} lands in Lines, filtered to this page.">
                            <x-pulse.button variant="signal" :href="pulse_route('lines', ['entity' => $entity->id])">Open Lines</x-pulse.button>
                        </x-pulse.empty>
                    @endif
                    @break
                @default
                    @php
                        $stats = EntityView::data($entity, $user);
                        $response = $stats['response'];
                        $counts = $stats['stats'];
                        $todos = EntityView::todos($entity, $user);
                    @endphp
                    <div class="bz-cols bz-cols--even">
                        <section class="pk-card" aria-labelledby="ov-todo">
                            <h2 id="ov-todo">Next</h2>
                            @if ($todos)
                                <ul class="bz-todos">
                                    @foreach ($todos as $todo)
                                        <li><a href="{{ $todo['href'] }}">{{ $todo['text'] }}</a></li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="pk-muted" style="margin-top: 8px">Nothing is waiting for you.</p>
                            @endif
                        </section>

                        <section class="pk-card" aria-labelledby="ov-stats">
                            <h2 id="ov-stats">What is real so far</h2>
                            @php $any = $response || $counts['confirmed_outcomes'] > 0 || $counts['offers'] > 0 || $counts['roles'] > 0 || $counts['conversations'] > 0; @endphp
                            @if ($any)
                                <div class="bz-stats">
                                    @if ($counts['offers'] > 0)<x-pulse.stat label="Offers on the floor" :value="$counts['offers']" />@endif
                                    @if ($counts['roles'] > 0)<x-pulse.stat label="Open roles" :value="$counts['roles']" />@endif
                                    @if ($counts['conversations'] > 0)<x-pulse.stat label="Lines started" :value="$counts['conversations']" />@endif
                                    @if ($response)<x-pulse.stat label="First messages answered" :value="$response['answered'].' of '.$response['received']" :note="'in the last '.\App\Support\Moment::plural((int) $response['window_days'], 'day')" />@endif
                                    @if ($response && $response['median_first_response_seconds'] !== null)<x-pulse.stat label="Usually within" :value="EntityView::duration($response['median_first_response_seconds'])" />@endif
                                    @if ($counts['confirmed_outcomes'] > 0)<x-pulse.stat label="Results both sides confirmed" :value="$counts['confirmed_outcomes']" />@endif
                                </div>
                            @else
                                <p class="pk-muted" style="margin-top: 8px">No numbers yet. They show up here when people reach out, and only from real lines.</p>
                            @endif
                        </section>
                    </div>
            @endswitch
        </div>
    </div>
</x-app-layout>
