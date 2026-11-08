{{--
    The knock sheet. A native dialog (kit drawer, bottom sheet): focus is trapped, Escape and the backdrop close it, the page
    behind is inert. The server state `open` drives it; the dialog tells the server when the browser closes it.
    wire:ignore.self keeps the dialog's own open attribute through Livewire's re-renders.
--}}
@php
    $dialogId = 'knock-'.$this->getId();
    $allowed = $verdict === null || ($verdict['allowed'] ?? true);
    $expiry = (int) config('pulse.lines.request_expiry_days', 14);
@endphp
<div
    class="fl-knock"
    x-data
    x-init="$wire.$watch('open', (isOpen) => { const d = $refs.sheet; if (!d) return; if (isOpen && !d.open) d.showModal(); if (!isOpen && d.open) d.close(); })"
>
    @unless ($bare)
        <x-pulse.button variant="signal" wire:click="openSheet" type="button" aria-haspopup="dialog">{{ $label }}</x-pulse.button>
    @endunless

    <x-pulse.drawer
        :id="$dialogId"
        :title="$sent ? 'Sent.' : ($kind === 'application' ? 'Say why you fit.' : ($kind === 'invite' ? 'Say why you are writing.' : 'Say what you want.'))"
        :description="$heading !== '' ? 'About: '.$heading : null"
        side="bottom"
        wire:ignore.self
        x-ref="sheet"
        x-on:close="if ($wire.open) $wire.close()"
    >
        @if ($sent)
            <div class="fl-sheet" role="status">
                <h3 class="fl-sheet__lead" tabindex="-1" x-init="$nextTick(() => $el.focus())">
                    {{ $lineStatus === 'open' ? 'The line is open.' : 'Now they decide.' }}
                </h3>
                <p>
                    {{ $lineStatus === 'open'
                        ? 'You can keep talking in Lines.'
                        : 'They can accept, say not now, or let it lapse. A request closes by itself after '.$expiry.' days.' }}
                </p>
                <div class="pk-cluster">
                    <x-pulse.button variant="primary" :href="pulse_route('lines.show', ['conversation' => $lineId])">Open the line</x-pulse.button>
                    <x-pulse.button variant="quiet" wire:click="close" type="button">Done</x-pulse.button>
                </div>
            </div>
        @elseif (! $allowed)
            <div class="fl-sheet">
                <p class="fl-notice" role="alert"><x-pulse.icon name="info" :size="18" /><span>{{ $verdict['why'] ?? 'You cannot knock here.' }}</span></p>
                @if (($verdict['code'] ?? '') === 'age')
                    <x-pulse.button variant="primary" :href="pulse_route_first(['me.profile', 'profile.show'])">Confirm your age</x-pulse.button>
                @endif
                <x-pulse.button variant="quiet" wire:click="close" type="button">Close</x-pulse.button>
            </div>
        @else
            <form class="fl-sheet" wire:submit="send" novalidate>
                <p class="pk-muted fl-small">Four short lines at most. No links in a first message. Contact details unlock if they say yes.</p>

                @if ($verdict)
                    <p class="fl-notice fl-notice--soft" role="status">
                        <x-pulse.icon name="info" :size="18" />
                        <span>
                            {{ $verdict['why'] }}
                            @if ($budget)
                                This uses one of your {{ $budget['left'] }} first contacts left this week.
                            @endif
                        </span>
                    </p>
                @endif

                @if ($namesYou)
                    <p class="fl-notice fl-notice--soft" role="status">
                        <x-pulse.icon name="info" :size="18" />
                        <span>You are anonymous to employers who browse. Applying shows this employer your name. Nobody else is told.</span>
                    </p>
                @endif

                @if ($failure)
                    <p class="fl-notice fl-notice--bad" role="alert"><x-pulse.icon name="alert" :size="18" /><span>{{ $failure }}</span></p>
                @endif

                <x-pulse.field name="what" :label="$kind === 'application' ? 'Why are you a fit?' : 'What do you want?'" wire:model="what" maxlength="160" required autofocus autocomplete="off" />
                <div class="fl-sheet__pair">
                    <x-pulse.field name="whenOrQty" label="When, or how many" wire:model="whenOrQty" maxlength="120" optional autocomplete="off" />
                    <x-pulse.field name="where" label="Where" wire:model="where" maxlength="120" optional autocomplete="off" />
                </div>
                <x-pulse.field name="budgetBand" label="Budget" wire:model="budgetBand" maxlength="80" optional autocomplete="off" />

                <div class="pk-cluster">
                    <x-pulse.button variant="signal" type="submit" wire:loading.attr="disabled" wire:target="send">{{ $label }}</x-pulse.button>
                    <x-pulse.button variant="quiet" data-pk-close type="button">Not now</x-pulse.button>
                </div>
            </form>
        @endif
    </x-pulse.drawer>
</div>
