@php
    use App\Livewire\Pulse\Business\Support\EntityView;
    $media = app(\App\Services\Pulse\Entities\MediaService::class);
    $statusWords = ['draft' => ['warn', 'Draft'], 'live' => ['good', 'On the floor'], 'paused' => ['warn', 'Paused'], 'archived' => ['neutral', 'Archived'], 'removed' => ['bad', 'Removed']];
@endphp
<div class="bz">
    @if ($notice)
        <div class="bz-notice" role="status"><x-pulse.icon name="info" :size="18" /><p>{{ $notice }}</p></div>
    @endif

    @if (! $this->entity->is_seller)
        <x-pulse.empty title="This page does not sell yet" text="Turn on selling in the Page tab to add offers." action="Open the Page tab" :href="route('entities.manage.page', ['entity' => $this->entity->slug])" />
    @elseif ($mode === 'list')
        <div class="bz-toolbar">
            <h2>Offers</h2>
            <x-pulse.button variant="signal" icon="plus" wire:click="newOffer">New offer</x-pulse.button>
        </div>

        @if ($this->offers->isEmpty())
            <x-pulse.empty title="No offers yet" text="An offer is one thing you sell: a product, a service, a package. People find it by what it is and where you are.">
                <x-pulse.button variant="signal" wire:click="newOffer">Write the first one</x-pulse.button>
            </x-pulse.empty>
        @else
            <div class="bz-list">
                @foreach ($this->offers as $offer)
                    @php [$tone, $word] = $statusWords[$offer->status] ?? ['neutral', ucfirst($offer->status)]; @endphp
                    <x-pulse.row :title="$offer->title" :href="route('offers.show', ['entity' => $this->entity->slug, 'offer' => $offer->slug])" wire:key="offer-{{ $offer->id }}">
                        <div class="pk-cluster" style="margin-top: 6px">
                            <x-pulse.pill :tone="$tone">{{ $word }}</x-pulse.pill>
                            @if ($offer->status !== 'removed' && $offer->moderation !== 'approved' && $offer->status !== 'draft')
                                <x-pulse.pill tone="warn">Waiting for a human</x-pulse.pill>
                            @endif
                            @if ($offer->moderation === 'removed')<x-pulse.pill tone="bad">Removed by a moderator</x-pulse.pill>@endif
                            <x-pulse.price :amount="EntityView::rand($offer->amount_cents)" :currency="$offer->currency" :unit="$offer->unit ? 'per '.$offer->unit : null" />
                        </div>
                        @if ($offer->moderation === 'removed' && $offer->moderation_reason)
                            <p class="pk-muted" style="margin-top: 6px">Removed: {{ $offer->moderation_reason }}</p>
                        @endif
                        @if ($offer->status !== 'draft' && in_array($offer->moderation, ['pending', 'held'], true))
                            <p class="pk-muted" style="margin-top: 6px">Waiting for a human: {{ $offer->moderation_reason ?: ($offer->moderation === 'held' ? 'The automatic check flagged something in the text.' : 'Pulse needs a person to look at this before it shows. Linking an InfoDot company helps next time.') }} {{ pulse_review_note() }}</p>
                        @endif
                        <x-slot name="end">
                            <div class="pk-cluster">
                                @if ($offer->moderation !== 'removed')
                                    <x-pulse.button size="sm" variant="quiet" wire:click="edit({{ $offer->id }})">Edit</x-pulse.button>
                                    @if ($offer->status === 'live')
                                        <x-pulse.button size="sm" variant="quiet" wire:click="pause({{ $offer->id }})">Pause</x-pulse.button>
                                    @elseif (in_array($offer->status, ['draft', 'paused', 'archived'], true))
                                        <x-pulse.button size="sm" variant="primary" wire:click="publish({{ $offer->id }})">Publish</x-pulse.button>
                                    @endif
                                    @if ($offer->status !== 'archived')
                                        <x-pulse.button size="sm" variant="quiet" wire:click="archive({{ $offer->id }})">Archive</x-pulse.button>
                                    @endif
                                @endif
                                <x-pulse.button size="sm" variant="danger" wire:click="delete({{ $offer->id }})" wire:confirm="Delete this offer for good? Lines that started from it keep their own copy.">Delete</x-pulse.button>
                            </div>
                        </x-slot>
                    </x-pulse.row>
                @endforeach
            </div>
        @endif
    @else
        <form class="bz-form" wire:submit="save" novalidate>
            <div class="bz-toolbar">
                <h2>{{ $offerId ? 'Edit offer' : 'New offer' }}</h2>
                <x-pulse.button type="button" variant="quiet" wire:click="cancel">Back to offers</x-pulse.button>
            </div>

            @if ($errors->any())
                <div class="bz-notice bz-notice--bad" role="alert"><x-pulse.icon name="alert" :size="18" /><p>{{ $errors->first() }}</p></div>
            @endif

            <section class="bz-block" aria-labelledby="of-what">
                <h3 id="of-what">What it is</h3>
                <x-pulse.field name="title" id="offer-title" label="Title" wire:model="title" maxlength="140" required />
                <x-pulse.field name="summary" id="offer-summary" label="One line" wire:model="summary" maxlength="240" hint="Shows on the card. Needed before you can publish." />
                <x-pulse.textarea name="description" id="offer-description" label="Details" wire:model="description" rows="6" maxlength="5000" optional />
                <div class="bz-pair">
                    <x-pulse.select name="kind" id="offer-kind" label="Kind" wire:model="kind" :options="['service' => 'Service', 'product' => 'Product']" />
                    <div class="bz-combo">
                        <x-pulse.field name="category" id="offer-category" label="Category" wire:model.live.debounce.250ms="category" optional autocomplete="off" />
                        @if ($this->categoryResults)
                            <ul class="bz-suggest" role="listbox" aria-label="Category suggestions">
                                @foreach ($this->categoryResults as $hit)
                                    <li><button type="button" role="option" wire:click="chooseCategory({{ \Illuminate\Support\Js::from($hit['label']) }})">{{ $hit['label'] }}</button></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
                <x-pulse.field name="tags_text" id="offer-tags" label="Tags" wire:model="tags_text" optional hint="Short words people would search, separated by commas." />
            </section>

            <section class="bz-block" aria-labelledby="of-price">
                <h3 id="of-price">Price and availability</h3>
                <div class="bz-pair">
                    <x-pulse.select name="price_mode" id="offer-price-mode" label="How you price it" wire:model.live="price_mode" :options="['fixed' => 'A fixed price', 'from' => 'From a price', 'quote' => 'On a quote', 'free' => 'Free', 'contact' => 'Ask me']" />
                    @if (in_array($price_mode, ['fixed', 'from'], true))
                        <x-pulse.field name="amount" id="offer-amount" label="Price in rand" wire:model="amount" inputmode="decimal" required />
                    @endif
                </div>
                @if (in_array($price_mode, ['fixed', 'from'], true))
                    <x-pulse.field name="unit" id="offer-unit" label="Per" wire:model="unit" maxlength="24" optional hint="For example hour, month, item." />
                @endif
                <div class="bz-pair">
                    <x-pulse.select name="availability" id="offer-availability" label="Availability" wire:model="availability" :options="['available' => 'Available', 'limited' => 'Limited', 'sold_out' => 'Sold out']" />
                    <x-pulse.field name="expires_at" id="offer-expires" type="date" label="Ends on" wire:model="expires_at" optional hint="Leave empty if it does not end." />
                </div>
                <x-pulse.toggle name="accepts_quotes" label="Let people ask for a quote" hint="A request goes to at most three businesses at once, so nobody is raced." :checked="$accepts_quotes" wire:model="accepts_quotes" />
            </section>

            <section class="bz-block" aria-labelledby="of-where">
                <h3 id="of-where">Where</h3>
                <div class="bz-combo">
                    <x-pulse.field name="place_query" id="offer-place" label="Place" wire:model.live.debounce.250ms="place_query" optional autocomplete="off" hint="Leave empty to use your page's place." />
                    @if ($place_id)<p class="bz-chosen"><x-pulse.icon name="pin" :size="14" />{{ $place_query }}</p>@endif
                    @if ($this->placeResults)
                        <ul class="bz-suggest" role="listbox" aria-label="Places">
                            @foreach ($this->placeResults as $hit)
                                <li><button type="button" role="option" wire:click="choosePlace({{ $hit['id'] }})">{{ $hit['label'] }}</button></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>

            <section class="bz-block" aria-labelledby="of-pics">
                <h3 id="of-pics">Pictures</h3>
                <p class="pk-muted">Up to {{ config('pulse.entities.max_offer_images', 8) }}. JPEG, PNG or WebP, 5 MB each.</p>
                @if ($media_ids)
                    <ul class="bz-gallery bz-gallery--edit">
                        @foreach ($media_ids as $mid)
                            <li wire:key="img-{{ $mid }}">
                                <img src="{{ $media->url($mid, 'thumb') }}" alt="Picture {{ $loop->iteration }}" loading="lazy">
                                <x-pulse.button type="button" size="sm" variant="quiet" wire:click="removeImage({{ $mid }})" aria-label="Remove picture {{ $loop->iteration }}">Remove</x-pulse.button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <x-pulse.field name="newImages" id="offer-images" label="Add pictures" optional>
                    <input id="offer-images" type="file" class="pk-control" multiple accept="image/jpeg,image/png,image/webp" wire:model="newImages">
                </x-pulse.field>
                <div wire:loading wire:target="newImages" class="pk-muted">Uploading</div>
            </section>

            <div class="bz-actions">
                <x-pulse.button variant="signal" type="button" wire:click="savePublish" wire:loading.attr="disabled" wire:target="save,savePublish,newImages">Save and publish</x-pulse.button>
                <x-pulse.button variant="quiet" type="submit" wire:loading.attr="disabled" wire:target="save,savePublish,newImages">{{ $offerId ? 'Save changes' : 'Save as draft' }}</x-pulse.button>
            </div>
        </form>
    @endif
</div>
