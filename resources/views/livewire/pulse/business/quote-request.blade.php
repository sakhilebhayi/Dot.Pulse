<div class="bz-quote">
    @if ($offer && $offer->accepts_quotes && ! $own)
        <h3>Ask for a quote</h3>
        <p class="pk-muted">A quote request goes to at most {{ $max }} businesses at once, so nobody is raced. This one goes to {{ $offer->entity->name }}. They pay nothing to see it and answer on one standard slip.</p>

        @if ($sent)
            <p class="bz-say bz-say--good" role="status">Sent. They can answer, decline or let it lapse, and you will hear which.</p>
            @if ($lineId)<x-pulse.button variant="primary" size="sm" :href="pulse_route('lines.show', ['conversation' => $lineId])">Open the line</x-pulse.button>@endif
        @elseif (! $open)
            <x-pulse.button variant="primary" wire:click="openForm">Ask for a quote</x-pulse.button>
        @else
            <form class="pk-stack" wire:submit="send" novalidate>
                @if ($failure)<p class="bz-say bz-say--warn" role="alert">{{ $failure }}</p>@endif
                <x-pulse.field name="what" id="quote-what" label="What do you need quoted" wire:model="what" maxlength="200" required />
                <x-pulse.field name="whenOrQty" id="quote-whenOrQty" label="When, or how many" wire:model="whenOrQty" maxlength="80" optional />
                <x-pulse.field name="where" id="quote-where" label="Where" wire:model="where" maxlength="80" optional />
                <x-pulse.field name="budgetBand" id="quote-budgetBand" label="Budget" wire:model="budgetBand" maxlength="40" optional hint="No links in a first message." />
                <div class="pk-cluster">
                    <x-pulse.button type="submit" variant="primary" wire:loading.attr="disabled">Send request</x-pulse.button>
                    <x-pulse.button type="button" variant="quiet" wire:click="close">Not now</x-pulse.button>
                </div>
            </form>
        @endif
    @endif
</div>
