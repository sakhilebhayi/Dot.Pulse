{{--
    One message. Needs: $m (LineService::thread() item), $viewerSide, $quotes (keyed), $canDecide (bool), $canReport (bool).
    Names come from the presenter only: an anonymous professional is an alias.
--}}
@php
    use App\Livewire\Pulse\Lines\Support\Words;
    $mine = $viewerSide !== null && $m['side'] === $viewerSide;
    $you = (bool) $m['mine'];
    $quote = $m['kind'] === 'quote' ? ($quotes[$m['meta']['quote_id'] ?? 0] ?? null) : null;
@endphp
@if ($m['kind'] === 'system')
    <p class="ln-system" id="m-{{ $m['id'] }}">
        <span>{{ Words::system($m, $viewerSide) }}</span>
        <time class="pk-mono" datetime="{{ $m['created_at']?->toIso8601String() }}">{{ Words::time($m['created_at']) }}</time>
    </p>
@elseif ($quote)
    <div class="ln-msg ln-msg--wide {{ $mine ? 'ln-msg--mine' : 'ln-msg--theirs' }}" id="m-{{ $m['id'] }}">
        <div class="ln-msg__from">{{ $you ? 'You' : $m['from'] }}<time class="pk-mono" datetime="{{ $m['created_at']?->toIso8601String() }}">{{ Words::time($m['created_at']) }}</time></div>
        @include('livewire.pulse.lines.partials.quote-slip', ['quote' => $quote, 'canDecide' => $canDecide])
    </div>
@else
    <div class="ln-msg {{ $mine ? 'ln-msg--mine' : 'ln-msg--theirs' }}{{ ! empty($m['meta']['card']) ? ' ln-msg--card' : '' }}" id="m-{{ $m['id'] }}">
        <div class="ln-msg__from">
            {{ $you ? 'You' : $m['from'] }}
            @if (! empty($m['teammate']))<span class="ln-msg__mate">{{ $m['teammate'] }}</span>@endif
            <time class="pk-mono" datetime="{{ $m['created_at']?->toIso8601String() }}" title="{{ Words::time($m['created_at']) }}">{{ Words::time($m['created_at']) }}</time>
        </div>
        <div class="ln-msg__bubble">
            @if (! empty($m['meta']['card']))
                @php
                    $labels = ['what' => 'What', 'when_or_qty' => 'When or how many', 'where' => 'Where', 'budget_band' => 'Budget'];
                @endphp
                <p class="ln-msg__tag">{{ $m['kind'] === 'quote_request' ? 'Asked for a quote' : 'First message' }}</p>
                <dl class="ln-msg__card">
                    @foreach ($labels as $key => $label)
                        @if (! empty($m['meta']['card'][$key]))<div><dt>{{ $label }}</dt><dd>{{ $m['meta']['card'][$key] }}</dd></div>@endif
                    @endforeach
                </dl>
            @elseif ($m['body'] !== '')
                <p class="ln-msg__text">{{ $m['body'] }}</p>
            @endif
            @if (! empty($m['media_id']))
                <a class="ln-msg__photo" href="{{ pulse_route('pulse.media', ['mediaFile' => $m['media_id'], 'size' => 'large']) }}" target="_blank" rel="noopener">
                    <img src="{{ pulse_route('pulse.media', ['mediaFile' => $m['media_id'], 'size' => 'medium']) }}" alt="Photo sent in this line" loading="lazy" decoding="async">
                </a>
            @endif
        </div>
        @if ($canReport && ! $mine)
            <div class="ln-msg__tools"><button type="button" class="ln-linkbtn" wire:click="reportMessage({{ $m['id'] }})">Report this message</button></div>
        @endif
    </div>
@endif
