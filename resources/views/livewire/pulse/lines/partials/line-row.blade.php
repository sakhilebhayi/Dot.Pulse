{{-- One line in a list. Needs: $line (present()). Shows what it is about, who, its state, the last words and when. --}}
@php
    use App\Livewire\Pulse\Lines\Support\Words;
    $cp = $line['counterpart'];
    $partyName = $cp['kind'] === 'anonymous' ? ($cp['alias'] ?? 'Anonymous professional') : $cp['name'];
    $state = Words::state($line);
    $forPage = $line['page']['name'] ?? null;
    $since = $line['last_message_at'] ?? $line['created_at'];
    $awaiting = $line['status'] === 'open' && $line['last_message_side'] !== null && $line['last_message_side'] !== $line['side'] && $since?->lte(now()->subHour());
@endphp
<x-pulse.row :title="$line['title']" :href="route('lines.show', $line['id'])" :unread="$line['unread']">
    <x-slot name="lead">@include('livewire.pulse.lines.partials.party', ['cp' => $cp, 'size' => 'md'])</x-slot>
    <div class="ln-meta">
        <span class="ln-who">{{ $line['side'] === 'initiator' ? 'To' : 'From' }} {{ $partyName }}</span>
        @if ($forPage)<span>For {{ $forPage }}</span>@endif
        <x-pulse.kind-word :kind="Words::kind($line['kind'])" />
    </div>
    <div class="ln-meta">
        <x-pulse.pill :tone="$state['tone']">{{ $state['label'] }}</x-pulse.pill>
        @if ($line['status'] === 'requested' && $line['side'] === 'initiator')
            <span>Asked {{ Words::span($line['created_at']) }} ago. Closes as no reply on {{ Words::date($line['expires_at']) }}.</span>
        @elseif ($awaiting)
            <span>Waiting for your reply for {{ Words::span($since) }}.</span>
        @endif
    </div>
    @if (! empty($line['snippet']))<p class="ln-snippet">{{ $line['snippet'] }}</p>@endif
    <x-slot name="end">
        <time class="pk-mono ln-time" datetime="{{ $since?->toIso8601String() }}" title="{{ Words::time($since) }}">{{ Words::ago($since) }}</time>
    </x-slot>
</x-pulse.row>
@if ($line['status'] === 'requested' && $line['side'] === 'initiator')
    <div class="ln-rowfoot">
        <button type="button" class="ln-linkbtn" wire:click="closeLine({{ $line['id'] }})">Withdraw this request</button>
    </div>
@endif
