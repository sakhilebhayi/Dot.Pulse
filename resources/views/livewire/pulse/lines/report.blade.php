<span class="ln-report">
    @if ($sent)
        <span class="ln-reported"><x-pulse.icon name="check" :size="14" />Reported. A moderator will look.</span>
    @else
        <button type="button" class="ln-linkbtn" wire:click="toggle" aria-expanded="{{ $open ? 'true' : 'false' }}">Report</button>

        @if ($open)
            <form wire:submit="submit" class="ln-report__form" aria-label="Report this {{ $noun }}">
                <h3>What is wrong with this {{ $noun }}?</h3>
                <x-pulse.field as="select" name="reason" :id="'report-reason-'.$this->getId()" label="Closest reason" wire:model="reason" placeholder="Pick one" :options="collect($reasons)->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all()" />
                <x-pulse.field as="textarea" name="note" :id="'report-note-'.$this->getId()" label="A little more" optional wire:model="note" rows="3" maxlength="500" hint="Moderators read this. They do not see your name, and the other side is not told who reported." />
                @if ($failure)<p class="ln-alert" role="alert"><x-pulse.icon name="alert" :size="16" />{{ $failure }}</p>@endif
                <div class="pk-cluster">
                    <x-pulse.button type="submit" variant="primary" size="sm">Send report</x-pulse.button>
                    <x-pulse.button variant="quiet" size="sm" wire:click="toggle">Cancel</x-pulse.button>
                </div>
            </form>
        @endif
    @endif
</span>
