@php
    use App\Livewire\Pulse\Business\Support\EntityView;
    use App\Services\Pulse\Entities\MediaService;
    use App\Services\Pulse\Entities\VerificationService;
    $media = app(MediaService::class);
    $places = app(\App\Services\Pulse\Discovery\Places\PlaceRepository::class);
    $manager = in_array($entity->memberRole(auth()->user()), ['owner', 'admin', 'editor'], true);
    $isMember = $entity->memberRole(auth()->user()) !== null;
    $images = collect((array) $offer->media_ids)->take(8)->values();
    $availability = ['available' => ['good', 'Available'], 'limited' => ['warn', 'Limited availability'], 'sold_out' => ['bad', 'Sold out']][$offer->availability] ?? ['neutral', ucfirst((string) $offer->availability)];
    $summary = app(VerificationService::class)->summary($entity);
@endphp
<x-app-layout :title="$offer->title" width="wide" :description="$offer->summary">
    <div class="bz bz-page">
        <p class="bz-crumb"><a href="{{ route('entities.show', ['entity' => $entity->slug]) }}">{{ $entity->name }}</a></p>

        @if ($manager && ! $offer->isLive())
            @if ($offer->moderation === 'removed' || ($offer->status === 'live' && in_array($offer->moderation, ['pending', 'held'], true)))
                @include('livewire.pulse.business.partials.held', ['state' => $offer->moderation, 'what' => 'offer'])
            @else
                <div class="bz-notice" role="status"><x-pulse.icon name="info" :size="18" /><p>This offer is {{ $offer->status === 'draft' ? 'a draft' : $offer->status }}. Only your team can see it. <a href="{{ route('entities.manage.offers', ['entity' => $entity->slug]) }}">Edit it</a>.</p></div>
            @endif
        @endif

        <header class="bz-offerhead">
            <div class="pk-stack" style="gap: 10px">
                <div class="pk-cluster">
                    <x-pulse.kind-word kind="offer" />
                    <x-pulse.pill :tone="$availability[0]">{{ $availability[1] }}</x-pulse.pill>
                    @if ($offer->place)<x-pulse.place-label :name="$places->label($offer->place)" />@endif
                </div>
                <h1 class="bz-name">{{ $offer->title }}</h1>
                @if ($offer->summary)<p class="bz-tagline">{{ $offer->summary }}</p>@endif
            </div>
            <div class="bz-priceblock">
                <x-pulse.price :amount="EntityView::rand($offer->amount_cents)" :currency="$offer->currency" :unit="$offer->unit ? 'per '.$offer->unit : null" :large="true" />
                @if (EntityView::priceNote($offer))<p class="pk-muted">{{ EntityView::priceNote($offer) }}</p>@endif
            </div>
        </header>

        <div class="bz-cols">
            <div class="bz-main pk-stack">
                @if ($images->isNotEmpty())
                    <ul class="bz-gallery" aria-label="Pictures">
                        @foreach ($images as $mediaId)
                            <li><a href="{{ $media->url((int) $mediaId, 'large') }}" target="_blank" rel="noopener"><img src="{{ $media->url((int) $mediaId, 'medium') }}" alt="Picture {{ $loop->iteration }} of {{ $offer->title }}" loading="lazy" decoding="async"></a></li>
                        @endforeach
                    </ul>
                @endif

                @if ($offer->description)
                    <section aria-labelledby="bz-desc">
                        <h2 id="bz-desc">About this {{ $offer->kind === 'product' ? 'product' : 'service' }}</h2>
                        <p class="bz-prose">{!! nl2br(e($offer->description)) !!}</p>
                    </section>
                @endif

                @if ($offer->tags || $offer->service_area || $offer->category)
                    <dl class="bz-facts">
                        @if ($offer->category)<div><dt>Category</dt><dd>{{ $offer->category }}</dd></div>@endif
                        @if ($offer->service_area)<div><dt>Serves</dt><dd>{{ implode(', ', $offer->service_area) }}</dd></div>@endif
                        @if ($offer->tags)<div><dt>Tags</dt><dd class="pk-cluster">@foreach ($offer->tags as $tag)<x-pulse.pill>{{ $tag }}</x-pulse.pill>@endforeach</dd></div>@endif
                        @if ($offer->expires_at)<div><dt>Offer ends</dt><dd>{{ $offer->expires_at->format('j M Y') }}</dd></div>@endif
                    </dl>
                @endif
            </div>

            <aside class="bz-side pk-stack" aria-label="Ask about this offer">
                <section class="pk-card pk-stack">
                    <a class="bz-from" href="{{ route('entities.show', ['entity' => $entity->slug]) }}">
                        <x-pulse.avatar :name="$entity->name" :src="$entity->logo_media_id ? $media->url($entity->logo_media_id, 'thumb') : null" entity />
                        <span><strong>{{ $entity->name }}</strong><span class="pk-muted">{{ $summary['label'] }}</span></span>
                    </a>
                    @if ($isMember)
                        <x-pulse.button variant="signal" :href="route('entities.manage.offers', ['entity' => $entity->slug])" :disabled="! $manager">Edit this offer</x-pulse.button>
                    @elseif ($offer->isLive())
                        @if ($offer->availability === 'sold_out')
                            <p class="pk-muted">This is sold out. You can still knock to ask when it is back.</p>
                        @endif
                        <livewire:pulse.knock-sheet :target="['entity' => $entity->id]" kind="enquiry" context-type="offer" :context-id="$offer->id" label="Ask about this" :key="'knock-offer-'.$offer->id" />
                        <livewire:pulse.business.quote-request :offer-id="$offer->id" :key="'quote-offer-'.$offer->id" />
                        <livewire:pulse.business.listing-actions type="offer" :id="$offer->id" :key="'actions-offer-'.$offer->id" />
                    @endif
                </section>
                <section class="pk-card" aria-labelledby="bz-trust">
                    <h2 id="bz-trust">About the seller</h2>
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
