@php
    use App\Livewire\Pulse\Business\Support\EntityView;
    use App\Services\Pulse\Entities\MediaService;
    use App\Services\Pulse\Entities\VerificationService;
    $media = app(MediaService::class);
    $places = app(\App\Services\Pulse\Discovery\Places\PlaceRepository::class);
    $isMember = $entity->memberRole(auth()->user()) !== null;
    $manager = in_array($entity->memberRole(auth()->user()), ['owner', 'admin', 'editor'], true);
    $ended = in_array($role->status, ['closed', 'filled'], true);
    $expired = $role->closes_at && $role->closes_at->isPast();
    $summary = app(VerificationService::class)->summary($entity);
    $state = match (true) {
        $role->status === 'filled' => ['good', 'Filled'],
        $role->status === 'closed' || $expired => ['neutral', 'Closed'],
        $role->status === 'paused' => ['warn', 'Paused'],
        $role->status === 'draft' => ['warn', 'Draft'],
        $role->isLive() => ['good', 'Open'],
        default => ['warn', ucfirst((string) $role->status)],
    };
    $open = $role->isLive();
@endphp
<x-app-layout :title="$role->title" width="wide" :description="$role->summary">
    <div class="bz bz-page">
        <p class="bz-crumb"><a href="{{ route('entities.show', ['entity' => $entity->slug]) }}">{{ $entity->name }}</a></p>

        @if ($manager && ! $open && ! $ended)
            @if ($role->moderation === 'removed' || ($role->status === 'live' && in_array($role->moderation, ['pending', 'held'], true)))
                @include('livewire.pulse.business.partials.held', ['state' => $role->moderation, 'what' => 'role', 'reason' => $role->moderation_reason])
            @else
                <div class="bz-notice" role="status"><x-pulse.icon name="info" :size="18" /><p>This role is {{ $role->status === 'draft' ? 'a draft' : $role->status }}. Only your team can see it. <a href="{{ route('entities.manage.roles', ['entity' => $entity->slug]) }}">Edit it</a>.</p></div>
            @endif
        @endif

        <header class="bz-offerhead">
            <div class="pk-stack" style="gap: 10px">
                <div class="pk-cluster">
                    <x-pulse.kind-word kind="role" />
                    <x-pulse.pill :tone="$state[0]">{{ $state[1] }}</x-pulse.pill>
                    @if ($role->place)<x-pulse.place-label :name="$places->label($role->place)" :remote="$role->work_mode === 'remote'" />@elseif ($role->work_mode === 'remote')<x-pulse.place-label :remote="true" />@endif
                </div>
                <h1 class="bz-name">{{ $role->title }}</h1>
                @if ($role->summary)<p class="bz-tagline">{{ $role->summary }}</p>@endif
            </div>
            @if ($role->pay_text)
                <div class="bz-priceblock"><span class="pk-price bz-pay"><span>{{ $role->pay_text }}</span></span><p class="pk-muted">As the employer wrote it</p></div>
            @endif
        </header>

        <div class="bz-cols">
            <div class="bz-main pk-stack">
                @if ($ended || $expired)
                    <div class="bz-notice" role="status"><x-pulse.icon name="info" :size="18" /><p>
                        @if ($role->status === 'filled') This role was filled. @else This role is closed and no longer takes applications. @endif
                        If you applied, your line in Lines still holds the conversation.
                    </p></div>
                @endif

                <dl class="bz-facts">
                    <div><dt>Type</dt><dd>{{ EntityView::employment()[$role->employment_type] ?? $role->employment_type }}</dd></div>
                    <div><dt>Where you work</dt><dd>{{ EntityView::workModes()[$role->work_mode] ?? $role->work_mode }}</dd></div>
                    @if ($role->seniority)<div><dt>Level</dt><dd>{{ ucfirst($role->seniority) }}</dd></div>@endif
                    @if ($role->closes_at)<div><dt>{{ $role->closes_at->isPast() ? 'Closed on' : 'Closes on' }}</dt><dd>{{ $role->closes_at->format('j M Y') }}</dd></div>@endif
                </dl>

                @if ($role->description)
                    <section aria-labelledby="bz-desc">
                        <h2 id="bz-desc">The role</h2>
                        <p class="bz-prose">{!! nl2br(e($role->description)) !!}</p>
                    </section>
                @endif

                @if ($role->skills)
                    <section aria-labelledby="bz-skills">
                        <h2 id="bz-skills">Skills they want</h2>
                        <div class="pk-cluster" style="margin-top: 10px">@foreach ($role->skills as $skill)<x-pulse.pill>{{ $skill }}</x-pulse.pill>@endforeach</div>
                        <p class="pk-muted" style="margin-top: 10px">Matching only decides who sees a role. It never rejects anyone.</p>
                    </section>
                @endif
            </div>

            <aside class="bz-side pk-stack" aria-label="Apply">
                <section class="pk-card pk-stack">
                    <a class="bz-from" href="{{ route('entities.show', ['entity' => $entity->slug]) }}">
                        <x-pulse.avatar :name="$entity->name" :src="$entity->logo_media_id ? $media->url($entity->logo_media_id, 'thumb') : null" entity />
                        <span><strong>{{ $entity->name }}</strong><span class="pk-muted">{{ $summary['label'] }}</span></span>
                    </a>
                    @if ($isMember)
                        @if ($manager)<x-pulse.button variant="signal" :href="route('entities.manage.roles', ['entity' => $entity->slug])">Edit this role</x-pulse.button>@else<p class="pk-muted">You are on this page's team. Only owners, admins and editors can change its roles.</p>@endif
                    @elseif ($open)
                        @if ($role->apply_mode === 'external_url' && $role->apply_url)
                            <x-pulse.button variant="signal" :href="$role->apply_url" target="_blank" rel="nofollow noopener ugc">Apply on their site</x-pulse.button>
                            <p class="pk-muted">This opens the employer's own site. You leave Pulse, so Pulse cannot see or follow what happens next.</p>
                        @else
                            <livewire:pulse.knock-sheet :target="['entity' => $entity->id]" kind="application" context-type="role" :context-id="$role->id" label="Apply" :key="'knock-role-'.$role->id" />
                            <p class="pk-muted">Four short lines. Contact details unlock if they say yes.</p>
                        @endif
                        <livewire:pulse.business.listing-actions type="role" :id="$role->id" :key="'actions-role-'.$role->id" />
                    @endif
                </section>
                <section class="pk-card" aria-labelledby="bz-trust">
                    <h2 id="bz-trust">About the employer</h2>
                    <p class="pk-muted" style="margin: 6px 0 10px">Checked means Pulse saw it in a real record. Claimed means they said it.</p>
                    @php $ladder = EntityView::ladder($entity); $nChecked = collect($ladder)->where('state', 'checked')->count(); $nClaimed = collect($ladder)->where('state', 'claimed')->count(); @endphp
                    <details class="bz-ladder">
                        <summary>{{ $nChecked }} checked, {{ $nClaimed }} claimed. Show the ladder</summary>
                        <x-pulse.ladder :rungs="$ladder" />
                    </details>
                </section>
            </aside>
        </div>
    </div>
</x-app-layout>
