<div class="bz-actionbar">
    @unless ($this->own)
        <div class="pk-cluster">
            @if ($this->signal)
                <x-pulse.button size="sm" variant="quiet" icon="bookmark" wire:click="toggleSave" aria-pressed="{{ $this->saved ? 'true' : 'false' }}">{{ $this->saved ? 'Saved' : 'Save' }}</x-pulse.button>
            @endif
            @if ($type === 'entity')
                <x-pulse.button size="sm" variant="quiet" wire:click="toggleFollow" aria-pressed="{{ $this->following ? 'true' : 'false' }}">{{ $this->following ? 'Following' : 'Follow' }}</x-pulse.button>
            @endif
            <x-pulse.button size="sm" variant="quiet" wire:click="openReport">Report</x-pulse.button>
            <x-pulse.button size="sm" variant="quiet" wire:click="askBlock">Block</x-pulse.button>
        </div>
    @endunless

    @if ($message)
        <p class="bz-say bz-say--{{ $messageTone }}" role="status">{{ $message }}</p>
    @endif

    @if ($reporting)
        <form class="bz-panel pk-stack" wire:submit="report" aria-labelledby="report-title-{{ $this->getId() }}">
            <h3 id="report-title-{{ $this->getId() }}">What is wrong with this?</h3>
            <x-pulse.select name="reason" label="Closest reason" wire:model="reason" placeholder="Pick one" :options="$this->reasons" />
            <x-pulse.textarea name="note" label="Anything we should know" wire:model="note" rows="3" maxlength="500" optional />
            <div class="pk-cluster">
                <x-pulse.button type="submit" variant="primary" size="sm">Send report</x-pulse.button>
                <x-pulse.button type="button" variant="quiet" size="sm" wire:click="closeReport">Cancel</x-pulse.button>
            </div>
        </form>
    @endif

    @if ($confirmingBlock)
        <div class="bz-panel pk-stack" role="alertdialog" aria-labelledby="block-title-{{ $this->getId() }}">
            <h3 id="block-title-{{ $this->getId() }}">Block {{ $type === 'need' ? 'this person' : 'this page' }}?</h3>
            <p>You will stop seeing each other anywhere on Pulse and any open line closes. They are not told. You can undo it in your privacy settings.</p>
            <div class="pk-cluster">
                <x-pulse.button type="button" variant="danger" size="sm" wire:click="block">Block</x-pulse.button>
                <x-pulse.button type="button" variant="quiet" size="sm" wire:click="cancelBlock">Cancel</x-pulse.button>
            </div>
        </div>
    @endif
</div>
