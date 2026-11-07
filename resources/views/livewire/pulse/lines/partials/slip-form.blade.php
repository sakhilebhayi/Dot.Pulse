{{-- The Quote Slip form: how a business answers a quote request. Standard fields, so slips can be compared. --}}
<section class="pk-card ln-slipform" aria-labelledby="ln-slipform-h">
    <h2 id="ln-slipform-h" class="ln-subhead">{{ $quotes->contains('status', 'sent') ? 'Send a new quote slip' : 'Answer on a quote slip' }}</h2>
    @if ($quotes->contains('status', 'sent'))
        <p class="ln-note"><x-pulse.icon name="info" :size="16" />You already have a slip out. A new one replaces it, and they see only the new one as holding.</p>
    @endif
    <p class="ln-muted">Same fields for everyone who is asked, so they can compare fairly. Put what the price covers and what it does not. You pay nothing to answer.</p>

    <form wire:submit="sendSlip" class="ln-slipform__grid">
        <x-pulse.field name="slip.amount" id="slip-amount" label="Price in rand, or the low end of a range" type="number" inputmode="decimal" step="0.01" min="1" wire:model="slip.amount" aria-required="true" />
        <x-pulse.field name="slip.amount_max" id="slip-amount-max" label="Top of the range" type="number" inputmode="decimal" step="0.01" min="1" wire:model="slip.amount_max" optional />
        <x-pulse.field name="slip.unit" id="slip-unit" label="Per what" wire:model="slip.unit" maxlength="24" optional placeholder="per hour, per job" />
        <x-pulse.field name="slip.earliest_date" id="slip-earliest" label="Earliest you can start" type="date" min="{{ now()->toDateString() }}" wire:model="slip.earliest_date" aria-required="true" />
        <div class="ln-slipform__wide">
            <x-pulse.field as="textarea" name="slip.included" id="slip-included" label="What the price includes" rows="3" maxlength="600" wire:model="slip.included" aria-required="true" />
        </div>
        <div class="ln-slipform__wide">
            <x-pulse.field as="textarea" name="slip.excluded" id="slip-excluded" label="What it does not include" rows="2" maxlength="600" wire:model="slip.excluded" optional hint="Say it now so there are no surprises later." />
        </div>
        <x-pulse.field name="slip.valid_until" id="slip-valid" label="This quote holds until" type="date" min="{{ now()->toDateString() }}" max="{{ now()->addDays((int) config('pulse.lines.quote_max_validity_days', 90))->toDateString() }}" wire:model="slip.valid_until" aria-required="true" hint="At most {{ (int) config('pulse.lines.quote_max_validity_days', 90) }} days." />
        <div class="ln-slipform__wide">
            <x-pulse.field as="textarea" name="slip.notes" id="slip-notes" label="A note" rows="2" maxlength="600" wire:model="slip.notes" optional hint="No links or contact details yet." />
        </div>
        <div class="ln-slipform__wide pk-cluster">
            <x-pulse.button type="submit" variant="signal" wire:loading.attr="disabled" wire:target="sendSlip">Send the quote</x-pulse.button>
        </div>
    </form>
</section>
