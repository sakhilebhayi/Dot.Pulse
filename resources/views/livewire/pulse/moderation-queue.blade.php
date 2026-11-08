@php
    $tabs = [
        'held' => ['label' => 'Posts', 'count' => $this->heldPosts->count()],
        'listings' => ['label' => 'Listings', 'count' => $platform ? $this->listings->count() : 0],
        'reports' => ['label' => 'Reports', 'count' => $this->reportCount],
        'lines' => ['label' => 'Messages and reviews', 'count' => $platform ? $this->lineCount : 0],
    ];
    if (! $platform) { unset($tabs['listings'], $tabs['lines']); }
@endphp
<div class="mq-body">
    <nav aria-label="Queue sections">
        <ul class="pk-tabs__list">
            @foreach ($tabs as $key => $t)
                <li><button type="button" class="pk-tab" wire:click="$set('tab', '{{ $key }}')" @if ($tab === $key) aria-current="page" @endif>{{ $t['label'] }}@if ($t['count'])<span class="pk-tab__count">{{ $t['count'] }}</span>@endif</button></li>
            @endforeach
        </ul>
    </nav>

    @if ($notice)<p class="mq-notice" role="status">{{ $notice }}</p>@endif

    {{-- ------------------------------------------------------------ Posts --}}
    @if ($tab === 'held')
        @if ($this->heldPosts->isEmpty())
            <x-pulse.empty title="Nothing waiting for review" text="Posts the rules are unsure about land here. Right now there are none." />
        @endif

        @foreach ($this->heldPosts as $post)
            @php
                $status = $post->enrichment?->moderation_status;
                $badge = match (true) {
                    $status === 'rejected' => ['AI recommended reject', 'bad'],
                    $status === 'flagged' => ['AI flagged for review', 'warn'],
                    default => ['Automated review unavailable', 'neutral'],
                };
            @endphp
            <article class="pk-card mq-item" wire:key="held-{{ $post->id }}">
                <header class="mq-item__head">
                    <div class="mq-item__who">
                        <x-pulse.kind-word :kind="ucfirst(str_replace('_', ' ', $post->type))" />
                        <span class="mq-item__by">by {{ $post->author->name }}</span>
                        <span class="pk-muted">{{ $post->community?->name ?? 'No community' }}@if ($post->community?->isPrivate()) (private)@endif</span>
                    </div>
                    <x-pulse.pill :tone="$badge[1]">{{ $badge[0] }}</x-pulse.pill>
                </header>

                @if ($post->title)<h3 class="mq-item__title">{{ $post->title }}</h3>@endif
                <p class="mq-item__body">{{ $post->body }}</p>

                @include('livewire.pulse.queue.flags', ['flags' => $this->postFlags[$post->id] ?? []])

                @if ($post->enrichment)
                    <div class="mq-meta">
                        @if ($post->enrichment->moderation_rationale)
                            <p>{{ $status === 'pending' ? 'Why it is here' : 'AI rationale' }}: {{ $post->enrichment->moderation_rationale }}</p>
                        @endif
                        @if ($status !== 'pending')
                            <p>Spam score: {{ number_format($post->enrichment->spam_score, 2) }} Safety score: {{ number_format($post->enrichment->safety_score, 2) }}</p>
                        @endif
                    </div>
                @endif

                @if ($rejectingPostId === $post->id)
                    <div class="mq-form">
                        <x-pulse.field :id="'reject-'.$post->id" name="rejectReason" label="Reason for rejecting" hint="The author reads this." wire:model="rejectReason" />
                        <div class="pk-cluster">
                            <x-pulse.button variant="danger" size="sm" wire:click="confirmReject({{ $post->id }})">Confirm reject</x-pulse.button>
                            <x-pulse.button variant="quiet" size="sm" wire:click="cancelReject">Cancel</x-pulse.button>
                        </div>
                    </div>
                @else
                    <div class="pk-cluster">
                        <x-pulse.button size="sm" wire:click="approve({{ $post->id }})">Approve</x-pulse.button>
                        <x-pulse.button variant="danger" size="sm" wire:click="promptReject({{ $post->id }})">Reject</x-pulse.button>
                    </div>
                @endif
            </article>
        @endforeach
    @endif

    {{-- ------------------------------------------------------------ Listings --}}
    @if ($tab === 'listings' && $platform)
        @if ($this->listings->isEmpty())
            <x-pulse.empty title="No listings are waiting" text="Offers, roles, needs and business pages the rules held for a person show up here, oldest first." />
        @else
            <div class="mq-bar">
                <p class="pk-muted">Oldest first. Holding or removing needs a reason, and the person who made it reads that reason.</p>
                <x-pulse.button variant="quiet" size="sm" wire:click="approveClean" wire:confirm="Approve every held listing the rules would let through today? Anything still flagged, and anything no rules checked, stays here.">Approve the clean ones</x-pulse.button>
            </div>
        @endif

        @foreach ($this->listings as $item)
            @php
                $deciding = $decidingKey === $item['key'];
                $why = $item['why'];
            @endphp
            <article class="pk-card mq-item" wire:key="listing-{{ $item['key'] }}">
                <header class="mq-item__head">
                    <div class="mq-item__who">
                        <x-pulse.kind-word :kind="ucfirst($item['noun'])" />
                        <span class="mq-item__by">@if ($item['author']) by {{ $item['author']->name }} @endif</span>
                        @if ($item['entity'])<span class="pk-muted">{{ $item['entity']->name }}</span>@endif
                    </div>
                    <x-pulse.pill tone="warn">{{ $item['moderation'] === 'held' ? 'Held by the rules' : 'Waiting, no rules ran' }}</x-pulse.pill>
                </header>

                <h3 class="mq-item__title">{{ $item['title'] }}</h3>
                @php $text = \App\Services\Pulse\Operator\ListingText::of($item['model']); @endphp
                <p class="mq-item__body">{{ \Illuminate\Support\Str::limit(preg_replace('/^'.preg_quote($item['title'], '/').'\s*/', '', $text), 600) }}</p>

                <div class="mq-why">
                    <p class="mq-why__note">{{ $why['note'] }} <span class="pk-muted">Waiting {{ $item['since']->diffForHumans(null, true) }}.</span></p>
                    @include('livewire.pulse.queue.flags', ['flags' => $why['flags']])
                </div>

                @if ($deciding)
                    <div class="mq-form">
                        <x-pulse.field :id="'listing-reason-'.str_replace(':', '-', $item['key'])" name="listingReason" :label="$decidingDecision === 'removed' ? 'Why it is being removed' : 'Why it is being held'" hint="The person who made it reads this." wire:model="listingReason" />
                        <div class="pk-cluster">
                            <x-pulse.button :variant="$decidingDecision === 'removed' ? 'danger' : 'primary'" size="sm" wire:click="confirmListing">{{ $decidingDecision === 'removed' ? 'Confirm removal' : 'Confirm hold' }}</x-pulse.button>
                            <x-pulse.button variant="quiet" size="sm" wire:click="cancelListing">Cancel</x-pulse.button>
                        </div>
                    </div>
                @else
                    <div class="pk-cluster">
                        <x-pulse.button size="sm" wire:click="approveListing('{{ $item['key'] }}')">Approve</x-pulse.button>
                        @if ($item['moderation'] !== 'held')
                            <x-pulse.button variant="quiet" size="sm" wire:click="promptListing('{{ $item['key'] }}', 'held')">Hold with a reason</x-pulse.button>
                        @endif
                        <x-pulse.button variant="danger" size="sm" wire:click="promptListing('{{ $item['key'] }}', 'removed')">Remove</x-pulse.button>
                    </div>
                @endif
            </article>
        @endforeach
    @endif

    {{-- ------------------------------------------------------------ Reports --}}
    @if ($tab === 'reports')
        @if ($reportGroups->isEmpty())
            <x-pulse.empty title="No open reports" text="When someone reports a post, a person, a page or a listing, it waits here." />
        @endif

        @foreach ($reportGroups as $group)
            @include('livewire.pulse.queue.group', ['group' => $group, 'd' => $this->describe($group), 'resolvingType' => $resolvingType, 'resolvingId' => $resolvingId, 'platform' => $platform])
        @endforeach
    @endif

    {{-- ------------------------------------------------------------ Messages and reviews --}}
    @if ($tab === 'lines' && $platform)
        <p class="pk-muted mq-lead">You see a message or a request here because someone reported it. Anonymous professionals stay anonymous.</p>

        @if ($lineGroups->isEmpty() && $this->heldReviews->isEmpty() && $this->flaggedResults->isEmpty())
            <x-pulse.empty title="Nothing reported in lines or reviews" text="Reported messages, requests and reviews, reviews the rules held, and results between the same two parties again and again show up here." />
        @endif

        @foreach ($this->flaggedResults as $result)
            <article class="pk-card mq-item" wire:key="result-{{ $result['id'] }}">
                <header class="mq-item__head">
                    <div class="mq-item__who">
                        <x-pulse.kind-word kind="Result" />
                        <span class="mq-item__by">{{ $result['initiator'] }} and {{ $result['recipient'] }}</span>
                    </div>
                    <x-pulse.pill tone="warn">Repeated pair</x-pulse.pill>
                </header>
                <h3 class="mq-item__title">{{ ucfirst($result['outcome']) }}@if ($result['about']), about {{ $result['about'] }}@endif</h3>
                <p class="mq-item__body">{{ $result['reason'] }} That is {{ $result['pair_results'] }} in the window. It is out of public counts until you decide.@if ($result['reviews_held']) {{ $result['reviews_held'] }} {{ $result['reviews_held'] === 1 ? 'review is' : 'reviews are' }} held with it.@endif</p>
                <div class="pk-cluster">
                    <x-pulse.button size="sm" wire:click="approveResult({{ $result['id'] }})">Approve</x-pulse.button>
                    <x-pulse.button variant="danger" size="sm" wire:click="discardResult({{ $result['id'] }})" wire:confirm="Discard this result? It will not count and the line can be marked again.">Discard</x-pulse.button>
                </div>
            </article>
        @endforeach

        @foreach ($lineGroups as $group)
            @include('livewire.pulse.queue.group', ['group' => $group, 'd' => $this->describe($group), 'resolvingType' => $resolvingType, 'resolvingId' => $resolvingId, 'platform' => $platform])
        @endforeach

        @foreach ($this->heldReviews as $review)
            @php $d = \App\Services\Pulse\Operator\ReportedSubject::describe($review); @endphp
            <article class="pk-card mq-item" wire:key="review-{{ $review->id }}">
                <header class="mq-item__head">
                    <div class="mq-item__who">
                        <x-pulse.kind-word kind="Review" />
                        <span class="mq-item__by">@if ($d['by']) by {{ $d['by'] }} @endif</span>
                    </div>
                    <x-pulse.pill tone="warn">Held by the rules</x-pulse.pill>
                </header>
                <h3 class="mq-item__title">{{ $d['title'] }}</h3>
                @if ($d['body'])<p class="mq-item__body">{{ $d['body'] }}</p>@endif

                @if ($removingReviewId === $review->id)
                    <div class="mq-form">
                        <x-pulse.field :id="'review-reason-'.$review->id" name="reviewReason" label="Why it is being removed" hint="The writer reads this." wire:model="reviewReason" />
                        <div class="pk-cluster">
                            <x-pulse.button variant="danger" size="sm" wire:click="confirmRemoveReview">Confirm removal</x-pulse.button>
                            <x-pulse.button variant="quiet" size="sm" wire:click="cancelRemoveReview">Cancel</x-pulse.button>
                        </div>
                    </div>
                @else
                    <div class="pk-cluster">
                        <x-pulse.button size="sm" wire:click="approveReview({{ $review->id }})">Approve</x-pulse.button>
                        <x-pulse.button variant="danger" size="sm" wire:click="promptRemoveReview({{ $review->id }})">Remove</x-pulse.button>
                    </div>
                @endif
            </article>
        @endforeach
    @endif
</div>
