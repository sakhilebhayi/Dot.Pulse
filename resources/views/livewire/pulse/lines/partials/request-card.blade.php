{{--
    A request, as a card with its four fields and the honest ways to answer. Needs: $line, $ignored (bool).
    The sender is named only as far as they may be: an anonymous professional is an alias and a card, never a name.
--}}
@php
    use App\Livewire\Pulse\Lines\Support\Words;
    $cp = $line['counterpart'];
    $partyName = $cp['kind'] === 'anonymous' ? ($cp['alias'] ?? 'Anonymous professional') : $cp['name'];
    $ignored = $ignored ?? false;
    $snap = $line['context']['snapshot'] ?? [];
    $forPage = $line['side'] === 'recipient' ? ($line['page']['name'] ?? null) : null;
@endphp
<article class="pk-card ln-request" aria-labelledby="req-{{ $line['id'] }}">
    <header class="ln-request__head">
        @include('livewire.pulse.lines.partials.party', ['cp' => $cp, 'size' => 'md'])
        <div class="ln-request__who">
            <div class="ln-who">
                @if ($cp['kind'] === 'person' && ! empty($cp['user_id']) && ($cp['linkable'] ?? true) && Route::has('users.show'))
                    <a href="{{ route('users.show', $cp['user_id']) }}">{{ $partyName }}</a>
                @elseif ($cp['kind'] === 'entity' && ! empty($cp['slug']) && Route::has('entities.show'))
                    <a href="{{ route('entities.show', $cp['slug']) }}">{{ $partyName }}</a>
                @else
                    {{ $partyName }}
                @endif
            </div>
            <div class="ln-meta">
                <x-pulse.kind-word :kind="Words::kind($line['kind'])" />
                @if ($forPage)<span>For {{ $forPage }}</span>@endif
                <time datetime="{{ $line['created_at']?->toIso8601String() }}" title="{{ Words::time($line['created_at']) }}" class="pk-mono">{{ Words::ago($line['created_at']) }}</time>
            </div>
        </div>
        @if ($line['unread'])<x-pulse.pill tone="signal">New</x-pulse.pill>@endif
    </header>

    <h2 class="ln-request__title" id="req-{{ $line['id'] }}">
        <a href="{{ route('lines.show', $line['id']) }}">{{ $line['title'] }}</a>
    </h2>

    @if ($cp['kind'] === 'anonymous')
        <p class="ln-note"><x-pulse.icon name="lock" :size="16" />Their name stays hidden until they choose to show it.</p>
        @include('livewire.pulse.lines.partials.anon-card', ['snap' => $snap])
    @endif

    @include('livewire.pulse.lines.partials.card-fields', ['card' => $line['card']])

    <p class="ln-nudge">
        @if ($ignored)
            You set this aside quietly. They still see it as waiting. It closes as no reply on {{ Words::date($line['expires_at']) }} unless you answer.
        @else
            Waiting for your answer for {{ Words::span($line['created_at']) }}. Answer, say not now, or let it lapse; it closes as no reply on {{ Words::date($line['expires_at']) }}.
        @endif
    </p>

    @include('livewire.pulse.lines.partials.request-actions', ['line' => $line, 'ignored' => $ignored])
</article>
