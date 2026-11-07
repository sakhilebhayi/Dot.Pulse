@php
    $statusWords = ['open' => ['good', 'Open'], 'closed' => ['neutral', 'Closed'], 'fulfilled' => ['good', 'Fulfilled'], 'removed' => ['bad', 'Removed']];
    [$tone, $word] = $statusWords[$need->status] ?? ['neutral', ucfirst($need->status)];
    $expired = $need->status === 'open' && $need->expires_at && $need->expires_at->isPast();
    if ($expired) { [$tone, $word] = ['neutral', 'Lapsed']; }
    $places = app(\App\Services\Pulse\Discovery\Places\PlaceRepository::class);
    $linesLabels = ['requested' => 'Waiting for an answer', 'open' => 'Open', 'declined' => 'Not now', 'closed' => 'Closed'];
@endphp
<div class="bz bz-page">
    @if (session('bz_notice'))
        <div class="bz-notice" role="status"><x-pulse.icon name="info" :size="18" /><p>{{ session('bz_notice') }}</p></div>
    @endif
    @if ($notice)
        <div class="bz-notice" role="status"><x-pulse.icon name="info" :size="18" /><p>{{ $notice }}</p></div>
    @endif

    @if ($this->isOwner && $need->moderation !== 'approved')
        @include('livewire.pulse.business.partials.held', ['state' => $need->moderation, 'what' => 'need', 'reason' => $need->moderation_reason])
    @endif

    <div class="bz-cols">
        <div class="bz-main pk-stack">
            <header class="pk-stack" style="gap: 10px">
                <div class="pk-cluster">
                    <x-pulse.kind-word kind="need" />
                    <x-pulse.pill :tone="$tone">{{ $word }}</x-pulse.pill>
                    <span class="pk-muted">{{ $kindLabel }}</span>
                    @if ($need->place)<x-pulse.place-label :name="$places->label($need->place)" />@endif
                </div>
                <h1 class="bz-name">{{ $need->title }}</h1>
                <p class="pk-muted">
                    @if ($this->isOwner)
                        You posted this{{ $need->entity ? ' for '.$need->entity->name : '' }}.
                    @else
                        Posted by {{ $need->entity?->name ?? $need->user?->name }}.
                    @endif
                    @if ($need->status === 'open' && ! $expired && $need->expires_at) It ends on {{ $need->expires_at->format('j M Y') }}.@endif
                </p>
            </header>

            @if ($need->details)
                <section aria-labelledby="nd-details">
                    <h2 id="nd-details">Details</h2>
                    <p class="bz-prose">{!! nl2br(e($need->details)) !!}</p>
                </section>
            @endif

            <dl class="bz-facts">
                @if ($need->budget_text)<div><dt>Budget</dt><dd>{{ $need->budget_text }}</dd></div>@endif
                @if ($need->needed_by)<div><dt>Needed by</dt><dd>{{ $need->needed_by->format('j M Y') }}</dd></div>@endif
                <div><dt>Reach</dt><dd>{{ ['local' => 'Near them', 'region' => 'In their region', 'national' => 'Anywhere in the country', 'global' => 'Anywhere', 'remote' => 'Online', 'city' => 'Near them', 'country' => 'Anywhere in the country'][$need->reach] ?? $need->reach }}</dd></div>
                @if ($this->isOwner)<div><dt>Who can open it</dt><dd>{{ $need->visibility === 'matched' ? 'Matched people only' : 'Any signed-in person, and people it fits' }}</dd></div>@endif
                @if ($need->tags)<div><dt>About</dt><dd class="pk-cluster">@foreach ($need->tags as $tag)<x-pulse.pill>{{ $tag }}</x-pulse.pill>@endforeach</dd></div>@endif
            </dl>

            @if ($this->isOwner)
                <section aria-labelledby="nd-lines" class="pk-stack">
                    <h2 id="nd-lines">Lines this started</h2>
                    @if ($this->lines)
                        <div class="bz-list">
                            @foreach ($this->lines as $line)
                                <x-pulse.row :title="($line['counterpart']['name'] ?? 'Someone')" :href="pulse_route('lines.show', ['conversation' => $line['id']])" :meta="$linesLabels[$line['status']] ?? ucfirst($line['status'])" :unread="$line['unread']" wire:key="line-{{ $line['id'] }}">
                                    <x-slot name="end"><span class="pk-mono pk-muted">{{ $line['created_at']?->format('j M') }}</span></x-slot>
                                </x-pulse.row>
                            @endforeach
                        </div>
                    @else
                        <p class="pk-muted">Nobody has knocked yet. When someone does, the line shows up here and in Lines.</p>
                    @endif
                </section>
            @endif
        </div>

        <aside class="bz-side pk-stack" aria-label="{{ $this->isOwner ? 'Manage this need' : 'Respond' }}">
            @if ($this->isOwner)
                <section class="pk-card pk-stack">
                    <h2>Your need</h2>
                    <div class="pk-stack" style="gap: 8px">
                        @if ($need->status === 'open' && ! $expired)
                            <x-pulse.button variant="signal" wire:click="fulfil">It worked out</x-pulse.button>
                            <x-pulse.button variant="quiet" wire:click="close" wire:confirm="Close this need? Nobody will be shown it any more.">Close it</x-pulse.button>
                        @elseif ($need->moderation !== 'removed' && $need->status !== 'removed')
                            <x-pulse.button variant="signal" wire:click="reopen">Open it again</x-pulse.button>
                        @endif
                        @if ($need->moderation !== 'removed')
                            <x-pulse.button variant="quiet" :href="route('needs.edit', ['need' => $need->id])">Edit</x-pulse.button>
                        @endif
                        <x-pulse.button variant="danger" wire:click="delete" wire:confirm="Delete this need for good?">Delete</x-pulse.button>
                    </div>
                </section>

                <section class="pk-card" aria-labelledby="nd-reach">
                    <h2 id="nd-reach">Who it was shown to</h2>
                    @if ($this->reach)
                        <div class="bz-stats">
                            <x-pulse.stat label="People it fits" :value="$this->reach['matched']" />
                            <x-pulse.stat label="Have seen it" :value="$this->reach['seen']" />
                        </div>
                        <p class="pk-muted" style="margin-top: 10px">Pulse tells you how many, never who. Anyone who wants to talk knocks.</p>
                    @else
                        <p class="pk-muted" style="margin-top: 8px">Not shown to anyone yet. When Pulse finds people it fits, the counts show up here.</p>
                    @endif
                </section>

                <section class="pk-card" aria-labelledby="nd-vis">
                    <h2 id="nd-vis">Open or matched</h2>
                    <p class="pk-muted" style="margin-top: 8px">Open means any signed-in person can read this page and it also goes to people it fits. Matched means only the people it fits are shown it.</p>
                </section>
            @elseif ($live)
                <section class="pk-card pk-stack">
                    <p>Can you help? Knock with a few short lines. They decide whether to take it further.</p>
                    <livewire:pulse.knock-sheet :target="['user' => $need->user_id]" :kind="$knockKind" context-type="need" :context-id="$need->id" :label="$knockKind === 'collab' ? 'Offer to collaborate' : ($knockKind === 'intro' ? 'Offer an introduction' : 'I can help')" :key="'knock-need-'.$need->id" />
                    <livewire:pulse.business.listing-actions type="need" :id="$need->id" :key="'actions-need-'.$need->id" />
                </section>
            @else
                <section class="pk-card"><p>This need is {{ $word === 'Lapsed' ? 'no longer open' : strtolower($word) }}.</p></section>
            @endif
        </aside>
    </div>
</div>
