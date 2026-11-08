@php
    $reasons = [
        ['code' => 'need_offer', 'text' => 'You need bookkeeping, they offer bookkeeping', 'weight' => 40],
        ['code' => 'place_region', 'text' => 'Both in Gauteng', 'weight' => 20],
        ['code' => 'responsive', 'text' => 'They usually answer within a day', 'weight' => 10],
    ];
@endphp
{{-- A Floor-shaped page built only from the kit, with sample text: the 680 px column, the context column and the dial. For QA and screenshots. --}}
<x-app-layout title="Floor, sample" width="floor" :dial="true">
    <x-slot name="context">
        <div class="pk-card pk-stack">
            <h2>Narrow the round</h2>
            <x-pulse.chips name="ctx_topics" legend="Topics" :options="['bookkeeping' => 'Bookkeeping', 'design' => 'Design', 'cycling' => 'Cycling']" :selected="['design']" />
            <x-pulse.toggle name="ctx_remote" label="Include remote" />
        </div>
        <div class="pk-card">
            <h2>Your door</h2>
            <p class="pk-muted" style="margin-top: 6px">Closed. Open it for a day or a week when you want strangers to knock.</p>
        </div>
    </x-slot>

    <div class="pk-enter">
        <x-pulse.beat kind="offer" title="Bookkeeping for small shops and sole traders" href="#" place="Johannesburg, Gauteng" time="2 h ago" open>
            Monthly books, VAT returns and a report you can read.
            <x-slot name="from">
                <x-pulse.avatar name="Sample Books" entity />
                <span class="pk-beat__from-text"><span class="pk-beat__from-name">Sample Books</span><x-pulse.pill tone="warn">Registration claimed</x-pulse.pill></span>
            </x-slot>
            <x-slot name="meter"><x-pulse.sync-meter :reasons="$reasons" remove="removeReason" /></x-slot>
            <x-slot name="actions">
                <x-pulse.button size="sm">Knock</x-pulse.button>
                <x-pulse.button size="sm" variant="quiet" icon="bookmark">Save</x-pulse.button>
            </x-slot>
            <x-slot name="aside"><x-pulse.price :amount="1200" unit="per month" /></x-slot>
        </x-pulse.beat>
        <x-pulse.beat kind="role" title="Junior accountant, hybrid" href="#" place="Pretoria, Gauteng" time="Today">
            Month-end close and reconciliations for a small manufacturing group.
            <x-slot name="from">
                <x-pulse.avatar name="Sample Works" entity />
                <span class="pk-beat__from-text"><span class="pk-beat__from-name">Sample Works</span><x-pulse.pill tone="good">InfoDot account checked</x-pulse.pill></span>
            </x-slot>
            <x-slot name="actions">
                <x-pulse.button size="sm">Knock</x-pulse.button>
                <x-pulse.button size="sm" variant="quiet" icon="bookmark">Save</x-pulse.button>
            </x-slot>
        </x-pulse.beat>
        <x-pulse.beat kind="ask" title="Which payroll tools handle leave carry-over properly?" href="#" time="Yesterday">
            Looking for something that copes with South African leave rules.
            <x-slot name="actions"><x-pulse.button size="sm" variant="quiet">Answer</x-pulse.button></x-slot>
        </x-pulse.beat>
    </div>

    <div class="pk-end">
        <h2>That's the round. You are caught up.</h2>
        <p>The next round is ready tomorrow at 07:00.</p>
    </div>
</x-app-layout>
