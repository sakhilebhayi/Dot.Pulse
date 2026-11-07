@php
    $user = auth()->user();
    $left = app(\App\Services\Pulse\Lines\KnockBudget::class)->remaining($user);
    $resets = app(\App\Services\Pulse\Lines\KnockBudget::class)->resetsAt($user);
@endphp
<x-app-layout title="Lines" width="floor" :dial="false" description="Every conversation on Pulse starts from something and ends. Answer requests, keep lines open, close the loop.">
    <x-slot name="header">
        <div>
            <h1 class="ln-pagetitle">Lines</h1>
            <p>Every line starts from something and ends. Nobody is left hanging.</p>
        </div>
    </x-slot>

    <livewire:pulse.lines.inbox :start-tab="request('tab')" :entity-id="request()->integer('as') ?: null" />

    <x-slot name="context">
        <div class="pk-card pk-stack">
            <h2>How a line ends</h2>
            <p class="ln-muted">Answered, declined with a short reason, or closed after 14 days with no reply. If you do nothing, the sender is told it closed. Not now is a fine answer.</p>
        </div>
        <div class="pk-card pk-stack">
            <h2>Your knocks</h2>
            <p><span class="pk-mono ln-big">{{ $left }}</span> first contacts left this week.</p>
            <p class="ln-muted">They refill when someone replies and come back in full on {{ $resets->format('j M') }}. They cannot be bought.</p>
        </div>
    </x-slot>
</x-app-layout>
