{{--
    A Quote Slip: price or range, earliest date, what is included, what is not, how long it holds.
    Needs: $quote (PulseQuote), $canDecide (bool: the person who asked), optional $name (the business), $stats (ResponseStats array or null).
--}}
@php
    use App\Livewire\Pulse\Lines\Support\Words;
    $stats = $stats ?? null;
    $lapsed = $quote->status === 'sent' && $quote->valid_until->lt(today());
    $status = $lapsed ? 'expired' : $quote->status;
    // When the person went with another business, this slip was not declined by them: say what happened.
    if (($closedReason ?? null) === 'not_chosen' && $status === 'declined') {
        $status = 'not_chosen';
    }
    $words = ['sent' => 'Holding', 'accepted' => 'Accepted', 'declined' => 'Declined', 'expired' => 'Expired', 'superseded' => 'Replaced by a newer slip', 'not_chosen' => 'Not chosen'];
    $tone = ['sent' => 'good', 'accepted' => 'good', 'declined' => 'neutral', 'expired' => 'warn', 'superseded' => 'neutral', 'not_chosen' => 'neutral'][$status] ?? 'neutral';
    $days = today()->diffInDays($quote->valid_until, false);
@endphp
<article class="ln-slip" aria-label="Quote slip{{ ! empty($name) ? ' from '.$name : '' }}">
    <header class="ln-slip__head">
        <div>
            <x-pulse.kind-word kind="Quote" />
            @if (! empty($name))<div class="ln-slip__name">{{ $name }}</div>@endif
        </div>
        <x-pulse.pill :tone="$tone">{{ $words[$status] ?? $status }}</x-pulse.pill>
    </header>

    <div class="ln-slip__price">
        <x-pulse.price :amount="$quote->amount_cents / 100" :to="$quote->isRange() ? $quote->amount_max_cents / 100 : null" :currency="$quote->currency" :unit="$quote->unit" :large="true" />
    </div>

    <dl class="ln-slip__list">
        <div><dt>Earliest start</dt><dd>{{ Words::date($quote->earliest_date) }}</dd></div>
        <div>
            <dt>Holds until</dt>
            <dd>
                {{ Words::date($quote->valid_until) }}
                @if ($status === 'sent')
                    <span class="ln-slip__left">{{ $days <= 0 ? 'last day' : ($days === 1 ? '1 day left' : $days.' days left') }}</span>
                @endif
            </dd>
        </div>
        <div class="ln-slip__wide"><dt>Included</dt><dd>{{ $quote->included }}</dd></div>
        <div class="ln-slip__wide"><dt>Not included</dt><dd>{{ $quote->excluded ?: 'Nothing was listed as left out.' }}</dd></div>
        @if ($quote->notes)<div class="ln-slip__wide"><dt>Note</dt><dd>{{ $quote->notes }}</dd></div>@endif
    </dl>

    @if ($stats && $stats['received'] > 0)
        @php
            $secs = $stats['median_first_response_seconds'];
            $usually = $secs === null ? null : ($secs < 3600 ? max(1, intdiv($secs, 60)).' min' : ($secs < 172800 ? round($secs / 3600).' h' : round($secs / 86400).' days'));
        @endphp
        <p class="ln-slip__stats">Answered {{ $stats['answered'] }} of {{ $stats['received'] }} requests in the last {{ $stats['window_days'] }} days{{ $usually ? ', usually within '.$usually : '' }}.</p>
    @endif

    @if ($canDecide && $status === 'sent')
        <div class="pk-cluster">
            <x-pulse.button variant="primary" size="sm" wire:click="acceptQuote({{ $quote->id }})">Accept this quote</x-pulse.button>
            <x-pulse.button variant="quiet" size="sm" wire:click="declineQuote({{ $quote->id }})">Decline</x-pulse.button>
        </div>
    @endif
</article>
