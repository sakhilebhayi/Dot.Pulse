@php
    use App\Livewire\Pulse\Lines\Support\Words;
    $empty = [
        'requests' => ['No requests waiting.', 'When someone knocks on something of yours, it lands here. You answer; nobody is left hanging.'],
        'open' => ['No lines yet.', 'Knock on something on the Floor.'],
        'sent' => ['Nothing waiting on anyone.', 'Requests you send wait here until they answer. Every one ends, even if the answer is silence.'],
        'closed' => ['Nothing closed yet.', 'Lines that end, one way or another, are kept here.'],
        'ignored' => ['Nothing set aside.', 'Requests you let lapse quietly wait here until they close.'],
    ][$tab];
@endphp
<div class="ln-inbox" wire:poll.visible.30s="refresh">
    @if ($pages->isNotEmpty())
        <div class="ln-switch" role="group" aria-label="Whose lines">
            <span class="ln-switch__label" id="ln-switch-label">Showing lines for</span>
            <div class="ln-switch__list" aria-labelledby="ln-switch-label">
                <button type="button" class="ln-chip" wire:click="switchTo(null)" aria-pressed="{{ $entityId === null ? 'true' : 'false' }}">All</button>
                @foreach ($pages as $page)
                    <button type="button" class="ln-chip" wire:click="switchTo({{ $page->id }})" aria-pressed="{{ $entityId === $page->id ? 'true' : 'false' }}" wire:key="sw-{{ $page->id }}">{{ $page->name }}</button>
                @endforeach
            </div>
        </div>
    @endif

    <nav aria-label="Lines">
        <ul class="pk-tabs__list">
            @foreach (\App\Livewire\Pulse\Lines\Inbox::TABS as $key => $label)
                <li>
                    <button type="button" class="pk-tab" wire:click="setTab('{{ $key }}')" @if ($tab === $key) aria-current="page" @endif>
                        {{ $label }}
                        @if ($key === 'requests' && $pending > 0)<span class="pk-tab__count">{{ $pending }}<span class="sr-only"> waiting for you</span></span>@endif
                        @if ($key === 'open' && $fresh > 0)<span class="ln-count">{{ $fresh }}<span class="sr-only"> lines with new messages</span></span>@endif
                    </button>
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="ln-filters">
        <label class="ln-filter">
            <span class="sr-only">Kind of line</span>
            <select class="pk-control" wire:model.live="kind" aria-label="Kind of line">
                <option value="">All kinds</option>
                @foreach (Words::KINDS as $code => $label)
                    <option value="{{ $code }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="ln-check">
            <input type="checkbox" wire:model.live="unreadOnly">
            <span>Unread only</span>
        </label>
    </div>

    @if ($failure)
        <p class="ln-alert" role="alert"><x-pulse.icon name="alert" :size="16" />{{ $failure }}</p>
    @endif

    @if ($lines->isEmpty())
        @if ($filtered)
            <x-pulse.empty title="Nothing matches that." text="Try another kind, or look at everything in this tab.">
                <x-pulse.button variant="quiet" wire:click="clearFilters">Clear the filter</x-pulse.button>
            </x-pulse.empty>
        @elseif ($tab === 'open')
            <x-pulse.empty :title="$empty[0]" :text="$empty[1]" action="Go to the Floor" :href="pulse_route_first(['floor', 'dashboard'])" />
        @else
            <x-pulse.empty :title="$empty[0]" :text="$empty[1]" />
        @endif
    @else
        <div class="ln-list pk-enter" aria-live="polite">
            @foreach ($lines as $line)
                <div wire:key="line-{{ $tab }}-{{ $line['id'] }}">
                    @if (in_array($tab, ['requests', 'ignored'], true))
                        @include('livewire.pulse.lines.partials.request-card', ['line' => $line, 'facts' => $facts, 'ignored' => $tab === 'ignored'])
                    @else
                        @include('livewire.pulse.lines.partials.line-row', ['line' => $line, 'facts' => $facts])
                    @endif
                </div>
            @endforeach
        </div>

        @if ($paginator->lastPage() > 1)
            <nav class="ln-pager" aria-label="More lines">
                <x-pulse.button variant="quiet" size="sm" wire:click="previousPage" :disabled="$paginator->currentPage() <= 1">Newer</x-pulse.button>
                <span class="pk-mono">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
                <x-pulse.button variant="quiet" size="sm" wire:click="nextPage" :disabled="! $paginator->hasMorePages()">Older</x-pulse.button>
            </nav>
        @endif
    @endif
</div>
