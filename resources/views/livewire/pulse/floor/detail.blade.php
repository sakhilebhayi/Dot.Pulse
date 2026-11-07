<div class="fl-detail">
    @if ($b === null)
        <x-pulse.empty title="That beat is gone." text="You removed the reason it was on your floor, so it left with it.">
            <x-pulse.button variant="signal" :href="pulse_route('floor')">Back to the floor</x-pulse.button>
        </x-pulse.empty>
    @else
        @php
            $anon = (bool) $b['anonymous'];
            $logo = ! empty($b['from']['entity']['logo_media_id']) ? pulse_route('pulse.media', ['mediaFile' => $b['from']['entity']['logo_media_id'], 'size' => 'thumb']) : null;
            $logo = $logo === '#' ? null : $logo;
            $aside = $b['aside'] ?? null;
            $sid = (int) $b['signal_id'];
        @endphp

        <article class="pk-beat pk-beat--open fl-detail__main">
            <div class="pk-beat__top">
                <x-pulse.kind-word :kind="$b['kind']" />
                @if (! empty($b['place']['label']))<x-pulse.place-label :name="$b['place']['label']" />@endif
                @if (! empty($b['when']))<span class="pk-beat__time pk-mono">{{ $b['when'] }}</span>@endif
            </div>

            <h1 class="fl-detail__title">{{ $b['title'] }}</h1>

            <div class="pk-beat__from">
                @if ($anon)
                    <x-pulse.avatar name="" />
                    <span class="pk-beat__from-text">
                        <span class="pk-beat__from-name">Anonymous professional</span>
                        <span class="fl-small">They choose whether to say who they are.</span>
                    </span>
                @else
                    <x-pulse.avatar :name="$b['from']['name'] ?? ''" :src="$logo ?? ($b['from']['avatar'] ?? null)" :entity="(bool) ($b['from']['entity'] ?? false)" />
                    <span class="pk-beat__from-text">
                        <span class="pk-beat__from-name">{{ $b['from']['name'] ?? 'Someone on Pulse' }}</span>
                        @if (! empty($b['verification']))
                            <span><x-pulse.pill :tone="$b['verification']['tone']">{{ $b['verification']['label'] }}</x-pulse.pill></span>
                        @endif
                    </span>
                @endif
            </div>

            @if ($anon)
                <dl class="fl-facts fl-facts--block">
                    @if (! empty($b['card']['skills']))<div><dt>Skills</dt><dd>{{ implode(', ', $b['card']['skills']) }}</dd></div>@endif
                    @if (! empty($b['card']['experience_band']))<div><dt>Experience</dt><dd>{{ $b['card']['experience_band'] }}</dd></div>@endif
                    @if (! empty($b['card']['region']))<div><dt>Region</dt><dd>{{ $b['card']['region'] }}</dd></div>@endif
                </dl>
                <p class="pk-muted">This is all an employer sees. A name, a photo and an employer history stay hidden until the professional says yes to an invitation.</p>
            @else
                @if (filled($body))
                    <div class="fl-detail__body">{{ \Illuminate\Support\Str::limit($body, 2400) }}</div>
                @elseif (filled($b['excerpt'] ?? null))
                    <div class="fl-detail__body">{{ $b['excerpt'] }}</div>
                @endif

                @if ($aside && ! empty($aside['price']))
                    <p class="fl-detail__price"><x-pulse.price :amount="$aside['price']['amount']" :currency="$aside['price']['currency']" :unit="$aside['price']['unit']" large /></p>
                @endif

                @php $noteRow = $aside && ! empty($aside['text']) && in_array($b['kind'], ['offer', 'door'], true); @endphp
                @if (count($facts) || $noteRow)
                    <dl class="fl-facts fl-facts--block">
                        @if ($noteRow)<div><dt>{{ $b['kind'] === 'door' ? 'Door' : 'Price' }}</dt><dd>{{ $aside['text'] }}</dd></div>@endif
                        @foreach ($facts as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
                    </dl>
                @endif

                @if ($postId)
                    <p><a href="{{ route('posts.show', $postId) }}">Read the whole post</a></p>
                @endif
            @endif

            <div class="pk-beat__actions">
                @if (! empty($b['knock']))
                    <x-pulse.button variant="signal" wire:click="$dispatch('pulse-knock', { spec: {{ \Illuminate\Support\Js::from($b['knock']) }} })">{{ $b['knock']['label'] }}</x-pulse.button>
                @endif

                @if (! $mine)
                    @if ($b['saved'] ?? false)
                        <x-pulse.button variant="quiet" icon="bookmark" wire:click="unsave({{ $sid }})" aria-pressed="true">Saved</x-pulse.button>
                    @else
                        <x-pulse.button variant="quiet" icon="bookmark" wire:click="save({{ $sid }})" aria-pressed="false">Save</x-pulse.button>
                    @endif
                @endif

                @if (! empty($b['knock_note']))
                    <span class="fl-small fl-knocknote">
                        @if (! empty($b['knock_href']) && $b['knock_href'] !== '#')<a href="{{ $b['knock_href'] }}">{{ $b['knock_note'] }}</a>@else{{ $b['knock_note'] }}@endif
                    </span>
                @endif
            </div>
        </article>

        <section class="pk-card fl-section" aria-labelledby="why-title">
            <h2 id="why-title">Why you are seeing this</h2>
            @if ($mine)
                <p class="pk-muted">This is yours. It is on the floor for people it fits.</p>
            @elseif (count($b['reasons']))
                <div class="fl-section__meter">
                    {{ \App\Livewire\Pulse\Floor\Support\MeterHtml::render($b['reasons'], $b['match_id'] ?? null, ! empty($b['match_id']), $anon ? 'Them' : \Illuminate\Support\Str::limit($b['from']['name'] ?? 'Them', 18, '')) }}
                </div>
                <p>Pulse introduced this because of {{ count($b['reasons']) === 1 ? 'the reason' : 'the '.count($b['reasons']).' reasons' }} above. Each one comes from something you told it or something you follow. Pulse does not use race, gender, age or anything like them to decide what you see.</p>
                <p class="pk-muted">Tap Stop using this on a reason and Pulse stops using that input for you, everywhere.</p>
            @else
                <p>No match put this in front of you. You opened it from a link, a search or a list, so there are no reasons to show.</p>
            @endif
        </section>

        @if (count($rungs))
            <section class="pk-card fl-section" aria-labelledby="from-title">
                <h2 id="from-title">Who it comes from</h2>
                <p class="fl-section__lead">
                    <strong>{{ $entity['name'] ?? '' }}</strong>
                    @if (! empty($entity['tagline']))<span class="pk-muted">{{ $entity['tagline'] }}</span>@endif
                </p>
                <x-pulse.ladder :rungs="$rungs" :summary="true" />
                @if (! empty($entity['slug']) && pulse_route('entities.show', ['entity' => $entity['slug']]) !== '#')
                    <p><a href="{{ pulse_route('entities.show', ['entity' => $entity['slug']]) }}">See the whole page</a></p>
                @endif
            </section>
        @endif

        @if ($answer)
            <section class="pk-card fl-section" aria-labelledby="ans-title">
                <h2 id="ans-title">{{ isset($stats['who']) ? 'How '.$stats['who'].' answers' : 'How they answer' }}</h2>
                <p>{{ $answer }}</p>
                <p class="pk-muted fl-small">Counted from real first messages. Requests that were ignored or lapsed count as not answered.</p>
            </section>
        @endif

        @if ($guide)
            <section class="pk-card fl-section" aria-labelledby="guide-title">
                <h2 id="guide-title">{{ $guide['title'] }}</h2>
                <ul class="fl-guide">
                    @foreach ($guide['lines'] as $line)<li>{{ $line }}</li>@endforeach
                </ul>
                <p class="pk-muted fl-small">Guidance, not protection. Pulse cannot guarantee how a deal goes.</p>
            </section>
        @endif

        <section class="fl-section" aria-label="Report, mute or block">
            @if (isset($postId) && $postId && ! $mine)
                <livewire:pulse.report-button target-type="post" :target-id="$postId" :key="'rp-'.$postId" />
            @else
                <livewire:pulse.floor.safety :signal-id="$signalId" :key="'sf-'.$signalId" />
            @endif
        </section>
    @endif
</div>
