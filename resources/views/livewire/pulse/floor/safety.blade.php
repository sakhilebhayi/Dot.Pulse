<div class="fl-safety">
@unless ($mine)
    @if ($notice)
        <p class="fl-notice fl-notice--bad" role="alert"><x-pulse.icon name="alert" :size="18" /><span>{{ $notice }}</span></p>
    @endif

    <div class="pk-cluster">
        @if ($reported)
            <x-pulse.pill tone="good">Reported</x-pulse.pill>
        @else
            <x-pulse.button size="sm" variant="quiet" :data-pk-dialog="$id.'-report'" icon="alert">Report</x-pulse.button>
        @endif

        @if ($targetKind)
            <x-pulse.button size="sm" variant="quiet" wire:click="mute" wire:loading.attr="disabled" wire:target="mute">Mute {{ $targetName }}</x-pulse.button>
            <x-pulse.button size="sm" variant="ghost" :data-pk-dialog="$id.'-block'">Block {{ $targetName }}</x-pulse.button>
        @endif
    </div>

    <x-pulse.drawer :id="$id.'-report'" title="Report this" side="right" description="A moderator reads it. The other side is not told who reported." wire:ignore.self>
        <form class="pk-stack" wire:submit="report" novalidate>
            <fieldset class="fl-radios fl-radios--stack">
                <legend>What is wrong?</legend>
                @foreach ($reasons as $value => $label)
                    <label class="fl-radio"><input type="radio" wire:model="reason" value="{{ $value }}"><span>{{ $label }}</span></label>
                @endforeach
                @error('reason')<p class="pk-field__error" role="alert"><x-pulse.icon name="alert" :size="16" />{{ $message }}</p>@enderror
            </fieldset>
            <x-pulse.textarea name="note" label="Tell us more" wire:model="note" maxlength="500" rows="3" optional />
            <div class="pk-cluster">
                <x-pulse.button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="report">Send report</x-pulse.button>
                <x-pulse.button variant="quiet" data-pk-close>Cancel</x-pulse.button>
            </div>
        </form>
    </x-pulse.drawer>

    @if ($targetKind)
        <x-pulse.confirm :id="$id.'-block'" :title="'Block '.$targetName.'?'" text="They vanish from your floor, open lines with them close, and neither side can contact the other. They are not told." label="Block" wire:click="block" />
    @endif
@endunless
</div>
