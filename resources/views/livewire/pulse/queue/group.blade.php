{{--
    One reported thing with everything reported about it. Reporter names are never loaded. Vars: group, d (described subject),
    resolvingType, resolvingId, platform
--}}
@php
    $target = $group['target'];
    $slug = $group['slug'];
    $resolving = $resolvingType === $group['type'] && $resolvingId === $group['id'];
    $removable = $target instanceof \App\Models\PulsePost || $target instanceof \App\Models\PulseComment || $target instanceof \App\Models\PulseReview
        || in_array($target ? $target::class : null, [\App\Models\PulseEntity::class, \App\Models\PulseOffer::class, \App\Models\PulseRole::class, \App\Models\PulseNeed::class], true);
    $isUser = $target instanceof \App\Models\User;
    $fieldId = 'resolve-'.$slug.'-'.$group['id'];
@endphp
<article class="pk-card mq-item" wire:key="report-{{ $group['key'] }}">
    <header class="mq-item__head">
        <div class="mq-item__who">
            <x-pulse.kind-word :kind="$d['kind']" />
            <span class="mq-item__by">
                @if ($d['by']) by {{ $d['by'] }} @elseif ($d['where']) {{ $d['where'] }} @endif
                @if ($d['by'] && $d['where']) <span class="pk-muted">{{ $d['where'] }}</span> @endif
            </span>
            @if ($group['community'])
                <span class="pk-muted">{{ $group['community'] }}@if ($group['private']) (private)@endif</span>
            @endif
        </div>
        <x-pulse.pill tone="bad">{{ $group['count'] }} {{ \Illuminate\Support\Str::plural('report', $group['count']) }}</x-pulse.pill>
    </header>

    @if ($d['title'])<h3 class="mq-item__title">{{ $d['title'] }}</h3>@endif
    @if ($d['body'])<p class="mq-item__body">{{ $d['body'] }}</p>@endif

    <ul class="mq-reasons" aria-label="Why it was reported">
        @foreach ($group['reasons'] as $reason => $n)
            <li><x-pulse.pill>{{ \App\Enums\ReportReason::tryFrom($reason)?->label() ?? ucfirst($reason) }} &times; {{ $n }}</x-pulse.pill></li>
        @endforeach
    </ul>

    @if (count($group['notes']))
        <ul class="mq-notes" aria-label="Notes from reporters">
            @foreach ($group['notes'] as $note)<li>{{ $note }}</li>@endforeach
        </ul>
    @endif

    @if ($resolving)
        <div class="mq-form">
            <x-pulse.field :id="$fieldId" name="resolveReason" label="Why it is being removed" hint="Kept in the moderation log. The person responsible reads it." wire:model="resolveReason" />
            <div class="pk-cluster">
                <x-pulse.button variant="danger" size="sm" wire:click="resolveReports('{{ $slug }}', {{ $group['id'] }})">Confirm removal</x-pulse.button>
                <x-pulse.button variant="quiet" size="sm" wire:click="cancelResolve">Cancel</x-pulse.button>
            </div>
        </div>
    @else
        <div class="pk-cluster">
            @if ($removable)
                <x-pulse.button variant="danger" size="sm" wire:click="promptResolve('{{ $slug }}', {{ $group['id'] }})">Remove {{ strtolower($d['kind']) }}</x-pulse.button>
            @elseif ($isUser)
                @if ($platform)<x-pulse.button size="sm" wire:click="upholdReports('{{ $slug }}', {{ $group['id'] }})">Confirm report</x-pulse.button>@endif
                <x-pulse.button variant="quiet" size="sm" wire:click="resolveReports('{{ $slug }}', {{ $group['id'] }})">Mark reviewed</x-pulse.button>
            @else
                <x-pulse.button size="sm" wire:click="upholdReports('{{ $slug }}', {{ $group['id'] }})">Confirm report</x-pulse.button>
            @endif
            <x-pulse.button variant="quiet" size="sm" wire:click="dismissReports('{{ $slug }}', {{ $group['id'] }})">Dismiss</x-pulse.button>
        </div>
    @endif
    @error('resolveReason')<p class="pk-field__error">{{ $message }}</p>@enderror
</article>
