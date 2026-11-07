@php
    use App\Livewire\Pulse\Business\Support\EntityView;
    $entity = $d['entity'];
    $isMember = $d['isMember'];
    $culture = (array) $entity->culture;
    $workModes = EntityView::workModes();
    $response = $d['response'];
    $stats = $d['stats'];
    // A guest sees the state of each rung, not the business's own details (registration number, website, company name).
    $ladder = $member ? $d['ladder'] : collect($d['ladder'])->map(fn ($r) => in_array($r['key'], ['registration', 'website', 'phone', 'infodot_team'], true) ? array_merge($r, ['detail' => null]) : $r)->all();
    $statusTone = ['infodot_verified' => 'good', 'infodot_member' => 'signal'][$entity->verification_status] ?? 'neutral';
@endphp
<div class="bz bz-page">
    @if (session('bz_notice'))
        <div class="bz-notice" role="status"><x-pulse.icon name="info" :size="18" /><p>{{ session('bz_notice') }}</p></div>
    @endif

    @if ($member && $isMember && (! $entity->isLive()))
        @if ($entity->status === 'suspended')
            <div class="bz-notice bz-notice--bad" role="status"><x-pulse.icon name="alert" :size="18" /><p>This page is suspended by a moderator. Only your team can see it.</p></div>
        @elseif ($entity->moderation !== 'approved')
            @include('livewire.pulse.business.partials.held', ['state' => $entity->moderation, 'what' => 'page', 'reason' => $entity->moderation_reason])
        @else
            <div class="bz-notice" role="status"><x-pulse.icon name="info" :size="18" /><p>This page is off the floor. Only your team can see it.</p></div>
        @endif
    @endif

    <header class="bz-hero">
        @if ($d['cover'])
            <div class="bz-cover"><img src="{{ $d['cover'] }}" alt="" decoding="async"></div>
        @endif
        <div class="bz-id">
            <x-pulse.avatar :name="$entity->name" :src="$d['logo']" size="xl" entity />
            <div class="bz-id__text">
                <h1 class="bz-name">{{ $entity->name }}</h1>
                @if ($entity->tagline)<p class="bz-tagline">{{ $entity->tagline }}</p>@endif
                <div class="pk-cluster bz-meta">
                    <x-pulse.pill :tone="$statusTone">{{ $d['status']['label'] }}</x-pulse.pill>
                    @if ($entity->is_seller)<x-pulse.pill>Sells</x-pulse.pill>@endif
                    @if ($entity->is_employer)<x-pulse.pill>Hires</x-pulse.pill>@endif
                    @if ($d['place'])<x-pulse.place-label :name="$d['place']" />@endif
                    @if ($entity->industry)<span class="pk-muted">{{ $entity->industry }}</span>@endif
                </div>
            </div>
        </div>
        <div class="bz-cta">
            @if ($member)
                @if ($isMember)
                    <x-pulse.button variant="signal" :href="route('entities.manage', ['entity' => $entity->slug])">Manage this page</x-pulse.button>
                @elseif ($entity->isLive())
                    <livewire:pulse.knock-sheet :target="['entity' => $entity->id]" kind="enquiry" label="Knock" :key="'knock-entity-'.$entity->id" />
                    <livewire:pulse.business.listing-actions type="entity" :id="$entity->id" :key="'actions-entity-'.$entity->id" />
                @endif
            @else
                <x-pulse.button variant="signal" :href="route('login')">Sign in with InfoDot to knock</x-pulse.button>
                <p class="pk-muted">Knocking, following and the full page are for signed-in people.</p>
            @endif
        </div>
    </header>

    <div class="bz-cols">
        <div class="bz-main pk-stack">
            @php $hasContent = filled($entity->about) || $d['offers']->isNotEmpty() || $d['roles']->isNotEmpty(); @endphp
            @unless ($hasContent)
                <x-pulse.empty :title="$isMember ? 'Nothing here yet' : 'Nothing published yet'" :text="$isMember ? 'Write an about and publish an offer or a role from Manage. They show up here the moment they are live.' : 'This business has not published an about, an offer or a role yet.'" />
            @endunless

            @if ($entity->about)
                <section aria-labelledby="bz-about">
                    <h2 id="bz-about">About</h2>
                    <p class="bz-prose">{!! nl2br(e($entity->about)) !!}</p>
                </section>
            @endif

            @if ($d['offers']->isNotEmpty())
                <section aria-labelledby="bz-offers">
                    <h2 id="bz-offers">What they offer</h2>
                    <div class="bz-list">
                        @foreach ($d['offers'] as $offer)
                            <x-pulse.row :title="$offer->title" :href="route('offers.show', ['entity' => $entity->slug, 'offer' => $offer->slug])" :meta="$member ? $offer->summary : null">
                                <x-slot name="end">
                                    @if ($member)
                                        <x-pulse.price :amount="EntityView::rand($offer->amount_cents)" :unit="$offer->unit ? 'per '.$offer->unit : null" :currency="$offer->currency" />
                                    @endif
                                </x-slot>
                            </x-pulse.row>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($d['roles']->isNotEmpty())
                <section aria-labelledby="bz-roles">
                    <h2 id="bz-roles">Open roles</h2>
                    <div class="bz-list">
                        @foreach ($d['roles'] as $role)
                            <x-pulse.row :title="$role->title" :href="route('roles.show', ['entity' => $entity->slug, 'role' => $role->slug])" :meta="$member ? $role->summary : null">
                                <x-slot name="end"><span class="pk-muted">{{ EntityView::closing($role) }}</span></x-slot>
                            </x-pulse.row>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($member && $entity->is_employer && $entity->hiring_signal !== 'none')
                <section aria-labelledby="bz-hiring">
                    <h2 id="bz-hiring">{{ $entity->hiring_signal === 'active' ? 'Hiring now' : 'Building a team' }}</h2>
                    <p>{{ $entity->hiring_signal === 'active' ? 'This business is hiring and wants to meet people with these skills.' : 'No vacancy yet. This business wants to meet people with these skills before it advertises.' }}</p>
                    @if ($entity->hiring_skills)
                        <div class="pk-cluster" style="margin-top: 10px">@foreach ($entity->hiring_skills as $skill)<x-pulse.pill>{{ $skill }}</x-pulse.pill>@endforeach</div>
                    @endif
                </section>
            @endif

            @if ($member && $entity->is_employer && (! empty($culture['values']) || ! empty($culture['perks']) || ! empty($culture['work_mode'])))
                <section aria-labelledby="bz-culture">
                    <h2 id="bz-culture">Working here</h2>
                    <dl class="bz-facts">
                        @if (! empty($culture['work_mode']))<div><dt>How they work</dt><dd>{{ $workModes[$culture['work_mode']] ?? $culture['work_mode'] }}</dd></div>@endif
                        @if (! empty($culture['values']))<div><dt>What they value</dt><dd>{{ implode(', ', $culture['values']) }}</dd></div>@endif
                        @if (! empty($culture['perks']))<div><dt>What they offer people</dt><dd>{{ implode(', ', $culture['perks']) }}</dd></div>@endif
                    </dl>
                </section>
            @endif
        </div>

        <aside class="bz-side pk-stack" aria-label="Trust and facts">
            <section class="pk-card" aria-labelledby="bz-trust">
                <h2 id="bz-trust">What Pulse can show</h2>
                <p class="pk-muted" style="margin: 6px 0 14px">Checked means Pulse saw it in a real record. Claimed means the business said it and Pulse has not checked.</p>
                <x-pulse.ladder :rungs="$ladder" :summary="true" />
            </section>

            @if ($response)
                <section class="pk-card" aria-labelledby="bz-resp">
                    <h2 id="bz-resp">How they answer</h2>
                    <div class="bz-stats">
                        <x-pulse.stat label="Answered" :value="round($response['response_rate'] * 100).'%'" :note="'of '.$response['received'].' in the last '.$response['window_days'].' days'" />
                        @if ($response['median_first_response_seconds'] !== null)
                            <x-pulse.stat label="Usually within" :value="EntityView::duration($response['median_first_response_seconds'])" />
                        @endif
                    </div>
                </section>
            @endif

            @if ($member && $stats['confirmed_outcomes'] > 0)
                <section class="pk-card">
                    <x-pulse.stat label="Results both sides confirmed" :value="$stats['confirmed_outcomes']" />
                </section>
            @endif

            @if ($member && ($entity->team_size || $entity->founded_year || $entity->service_area || $entity->website || $entity->public_email || $entity->public_phone))
                <section class="pk-card" aria-labelledby="bz-facts">
                    <h2 id="bz-facts">Details</h2>
                    <dl class="bz-facts">
                        @if ($entity->team_size)<div><dt>Team size</dt><dd>{{ $entity->team_size === '1' ? 'Just one person' : $entity->team_size.' people' }}</dd></div>@endif
                        @if ($entity->founded_year)<div><dt>Founded</dt><dd>{{ $entity->founded_year }}</dd></div>@endif
                        @if ($entity->service_area)<div><dt>Serves</dt><dd>{{ implode(', ', $entity->service_area) }}</dd></div>@endif
                        @if ($entity->website)<div><dt>Website <span class="pk-muted">(claimed)</span></dt><dd><a href="{{ $entity->website }}" rel="nofollow noopener ugc" target="_blank">{{ preg_replace('#^https?://#', '', rtrim($entity->website, '/')) }}</a></dd></div>@endif
                        @if ($entity->public_email)<div><dt>Email <span class="pk-muted">(claimed)</span></dt><dd><a href="mailto:{{ $entity->public_email }}">{{ $entity->public_email }}</a></dd></div>@endif
                        @if ($entity->public_phone)<div><dt>Phone <span class="pk-muted">(claimed)</span></dt><dd>{{ $entity->public_phone }}</dd></div>@endif
                    </dl>
                </section>
            @endif
        </aside>
    </div>
</div>
