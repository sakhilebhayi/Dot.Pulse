{{--
    What the person who was asked can do about a request. Needs: $line (present()), $ignored (bool, optional).
    Not now is a fine answer: the sender hears only the short reason picked here.
--}}
@php
    $id = $line['id'];
    $ignored = $ignored ?? false;
    $cp = $line['counterpart'];
    $partyName = $cp['kind'] === 'anonymous' ? ($cp['alias'] ?? 'this professional') : $cp['name'];
@endphp
<div class="ln-actions">
    @if ($declining === $id)
        <form class="ln-reasons" wire:submit="decline">
            <fieldset>
                <legend>Not now is a fine answer. Pick a short reason. They see only this.</legend>
                @foreach (config('pulse.lines.decline_reasons', []) as $code => $label)
                    <label class="ln-reason">
                        <input type="radio" name="decline-{{ $id }}" value="{{ $code }}" wire:model="declineReason">
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </fieldset>
            <div class="pk-cluster">
                <x-pulse.button type="submit" variant="primary" size="sm">Send answer</x-pulse.button>
                <x-pulse.button variant="quiet" size="sm" wire:click="cancelDecline">Back</x-pulse.button>
            </div>
        </form>
    @elseif ($blocking === $id)
        <div class="ln-confirm" role="group" aria-label="Confirm block">
            <p><strong>Block {{ $partyName }}?</strong> You will not see each other and every open line between you closes. They are not told.</p>
            <div class="pk-cluster">
                <x-pulse.button variant="danger" size="sm" wire:click="confirmBlock">Block</x-pulse.button>
                <x-pulse.button variant="quiet" size="sm" wire:click="cancelBlock">Back</x-pulse.button>
            </div>
        </div>
    @else
        <div class="pk-cluster">
            @if ($line['kind'] === 'quote')
                <x-pulse.button variant="signal" size="sm" :href="route('lines.show', $id)">Open and quote</x-pulse.button>
            @else
                <x-pulse.button variant="signal" size="sm" wire:click="accept({{ $id }})" wire:loading.attr="disabled" wire:target="accept({{ $id }})">Accept</x-pulse.button>
            @endif
            <x-pulse.button variant="quiet" size="sm" wire:click="chooseDecline({{ $id }})">Not now</x-pulse.button>
            @unless ($ignored)
                <x-pulse.button variant="quiet" size="sm" wire:click="ignore({{ $id }})">Ignore</x-pulse.button>
            @endunless
        </div>
        <div class="ln-minor">
            @if ($cp['kind'] !== 'anonymous')
                <button type="button" class="ln-linkbtn" wire:click="askBlock({{ $id }})">Block</button>
            @endif
            <livewire:pulse.lines.report subject-type="conversation" :subject-id="$id" :key="'report-'.$id" />
        </div>
    @endif
</div>
