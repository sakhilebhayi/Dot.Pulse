@php use App\Livewire\Pulse\Lines\Support\Words; @endphp
<div class="ln-compare-page" wire:poll.visible.30s="refresh">
    <div class="ln-back"><a href="{{ route('lines') }}">All lines</a></div>

    <header class="ln-compare__head">
        <x-pulse.kind-word kind="Quotes" />
        <h1 class="ln-title">{{ $title }}</h1>
        <p class="ln-muted">You asked {{ $columns->count() === 1 ? 'one business' : $columns->count().' businesses' }} on the same card. Same fields, so the slips compare fairly. Price is only one of them.</p>
        @include('livewire.pulse.lines.partials.card-fields', ['card' => $card])
    </header>

    @if ($failure)<p class="ln-alert" role="alert"><x-pulse.icon name="alert" :size="16" />{{ $failure }}</p>@endif

    @if ($chosen)
        <p class="ln-note ln-note--block"><x-pulse.icon name="check" :size="16" />You accepted the quote from {{ $chosen['entity']->name }}. Next, mark what it came to once it is done. <a href="{{ route('lines.show', $chosen['conversation']->id) }}">Open that line</a></p>
    @endif

    <div class="ln-columns ln-columns--{{ $columns->count() }}">
        @foreach ($columns as $col)
            <section class="ln-col" wire:key="col-{{ $col['conversation']->id }}" aria-label="{{ $col['entity']?->name }}">
                @if ($col['quote'])
                    @include('livewire.pulse.lines.partials.quote-slip', ['quote' => $col['quote'], 'canDecide' => true, 'name' => $col['entity']?->name, 'stats' => $col['stats']])
                @else
                    @php $state = Words::state($col['line']); @endphp
                    <article class="ln-slip ln-slip--empty" aria-label="No slip from {{ $col['entity']?->name }}">
                        <header class="ln-slip__head">
                            <div><x-pulse.kind-word kind="Quote" /><div class="ln-slip__name">{{ $col['entity']?->name }}</div></div>
                            <x-pulse.pill :tone="$state['tone']">{{ $state['label'] }}</x-pulse.pill>
                        </header>
                        @if ($col['line']['status'] === 'requested')
                            <p class="ln-muted">No slip yet. Asked {{ Words::span($col['line']['created_at']) }} ago. If nobody answers it closes as no reply on {{ Words::date($col['line']['expires_at']) }}.</p>
                        @elseif ($col['line']['status'] === 'declined')
                            <p class="ln-muted">{{ $col['line']['declined_label'] ?: 'Not now' }}. They are not quoting for this one.</p>
                        @else
                            <p class="ln-muted">{{ $state['label'] }}. There is no slip to compare.</p>
                        @endif
                        @if ($col['stats'] && $col['stats']['received'] > 0)
                            <p class="ln-slip__stats">Answered {{ $col['stats']['answered'] }} of {{ $col['stats']['received'] }} requests in the last {{ $col['stats']['window_days'] }} days.</p>
                        @endif
                    </article>
                @endif
                <p class="ln-col__link"><a href="{{ route('lines.show', $col['conversation']->id) }}">Open the line with {{ $col['entity']?->name }}</a></p>
            </section>
        @endforeach
    </div>
</div>
