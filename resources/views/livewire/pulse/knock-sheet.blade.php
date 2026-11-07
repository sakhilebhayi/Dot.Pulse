<div class="pk-knock">
    <x-pulse.button variant="signal" wire:click="openSheet" type="button">{{ $label }}</x-pulse.button>

    @if ($open)
        <section class="pk-sheet" role="dialog" aria-modal="true" aria-labelledby="knock-title-{{ $this->getId() }}">
            @if ($sent)
                <h2 id="knock-title-{{ $this->getId() }}" class="pk-title">
                    {{ $lineStatus === 'open' ? 'Sent. The line is open.' : 'Sent. Now they decide.' }}
                </h2>
                <p>{{ $lineStatus === 'open' ? 'You can keep talking in Lines.' : 'They can say yes, say not now, or let it lapse. You will hear either way.' }}</p>
                <x-pulse.button variant="primary" :href="pulse_route('lines.show', ['conversation' => $lineId])">Open the line</x-pulse.button>
                <x-pulse.button variant="quiet" wire:click="close" type="button">Done</x-pulse.button>
            @else
                <h2 id="knock-title-{{ $this->getId() }}" class="pk-title">Say what you want.</h2>
                <p class="pk-hint">Four short lines at most. No links in a first message. Contact details unlock if they say yes.</p>

                @if ($verdict && ! ($verdict['allowed'] ?? true))
                    <p role="alert" class="pk-error">{{ $verdict['why'] ?? 'You cannot knock here.' }}</p>
                @endif
                @if ($failure)
                    <p role="alert" class="pk-error">{{ $failure }}</p>
                @endif

                <form wire:submit="send">
                    <x-pulse.field name="what" label="What do you want?" wire:model="what" maxlength="160" required />
                    <x-pulse.field name="whenOrQty" label="When, or how many" wire:model="whenOrQty" maxlength="120" optional />
                    <x-pulse.field name="where" label="Where" wire:model="where" maxlength="120" optional />
                    <x-pulse.field name="budgetBand" label="Budget" wire:model="budgetBand" maxlength="80" optional />
                    <x-pulse.button variant="signal" type="submit">Knock</x-pulse.button>
                    <x-pulse.button variant="quiet" wire:click="close" type="button">Not now</x-pulse.button>
                </form>
            @endif
        </section>
    @endif
</div>
