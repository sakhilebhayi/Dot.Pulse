<span class="lg-report">
    @if($this->alreadyReported)
        <span class="lg-link lg-link--done" title="A moderator will take a look. You will hear back once it is reviewed.">Reported</span>
    @else
        <button wire:click="toggle" type="button" class="lg-link" aria-expanded="{{ $open ? 'true' : 'false' }}">Report</button>

        @if($open)
            <form wire:submit="submit" class="pk-card lg-pop pk-stack">
                <h3 class="lg-h">What is wrong with this {{ $targetType === 'user' ? 'profile' : $targetType }}?</h3>
                <x-pulse.field as="select" :id="'report-reason-'.$targetType.'-'.$targetId" name="reason" label="Reason" wire:model="reason" placeholder="Choose a reason"
                    :options="collect(\App\Enums\ReportReason::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all()" />
                <x-pulse.field as="textarea" :id="'report-note-'.$targetType.'-'.$targetId" name="note" label="Note" optional hint="Needed if you pick something else." rows="3" maxlength="500" wire:model="note" />
                <p class="pk-muted lg-small">Moderators see your note, not your name. The author is not told who reported.</p>
                <div class="pk-cluster">
                    <x-pulse.button type="submit" size="sm">Send report</x-pulse.button>
                    <x-pulse.button variant="quiet" size="sm" wire:click="toggle">Cancel</x-pulse.button>
                </div>
            </form>
        @endif
    @endif
</span>
