{{--
    The outcome bar: what did this line come to? One side proposes, the other confirms, and nothing counts until both have.
    Needs: $o (outcomeState), $line.
--}}
@php
    use App\Livewire\Pulse\Lines\Support\Words;
    $label = Words::outcome($o['value']);
@endphp
<section class="pk-card ln-outcome" aria-labelledby="ln-outcome-h">
    <h2 id="ln-outcome-h" class="ln-subhead">What did this come to?</h2>

    @if ($o['confirmed'])
        <p class="ln-outcome__done">
            <x-pulse.icon name="check" :size="18" />
            <span><strong>Both sides confirmed: {{ $label }}.</strong>
            @if ($o['positive']) This counts as a result. @else This closes the loop. It is not counted as a result. @endif</span>
        </p>
    @elseif ($o['value'] !== 'none' && $o['mine'])
        <ol class="ln-agree" aria-label="Who has confirmed">
            <li class="is-done"><span class="ln-agree__mark" aria-hidden="true"><x-pulse.icon name="check" :size="14" /></span><span><strong>You</strong> marked it: {{ $label }}</span></li>
            <li><span class="ln-agree__mark" aria-hidden="true"></span><span><strong>Them</strong> have not confirmed yet</span></li>
        </ol>
        <p class="ln-muted">It does not count yet. It counts when they confirm it. You can change what you marked.</p>
        <div class="ln-choices">
            @foreach ($o['choices'] as $choice)
                @if ($choice !== $o['value'])
                    <x-pulse.button variant="quiet" size="sm" wire:click="proposeOutcome('{{ $choice }}')">Change to {{ Words::outcome($choice) }}</x-pulse.button>
                @endif
            @endforeach
        </div>
    @elseif ($o['value'] !== 'none' && $o['awaiting_me'])
        <ol class="ln-agree" aria-label="Who has confirmed">
            <li class="is-done"><span class="ln-agree__mark" aria-hidden="true"><x-pulse.icon name="check" :size="14" /></span><span><strong>They</strong> marked it: {{ $label }}</span></li>
            <li><span class="ln-agree__mark" aria-hidden="true"></span><span><strong>You</strong> have not confirmed yet</span></li>
        </ol>
        <p class="ln-muted">It does not count until you confirm it. If it is wrong, dispute it and it is cleared.</p>
        <div class="pk-cluster">
            <x-pulse.button variant="signal" size="sm" wire:click="confirmOutcome">Yes, confirm: {{ $label }}</x-pulse.button>
            <x-pulse.button variant="quiet" size="sm" wire:click="disputeOutcome">That is not right</x-pulse.button>
        </div>
    @else
        @if ($o['disputed'])
            <p class="ln-note"><x-pulse.icon name="info" :size="16" />The last outcome was disputed and cleared. Talk it through, then mark it again.</p>
        @endif
        <p class="ln-muted">Mark it when it is done. The other side confirms it. Nothing counts until you both agree.</p>
        <div class="ln-choices">
            @foreach ($o['choices'] as $choice)
                <x-pulse.button :variant="$choice === 'lost' ? 'quiet' : 'primary'" size="sm" wire:click="proposeOutcome('{{ $choice }}')">{{ Words::outcome($choice) }}</x-pulse.button>
            @endforeach
        </div>
    @endif
</section>
