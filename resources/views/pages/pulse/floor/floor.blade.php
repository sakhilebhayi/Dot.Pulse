@php
    $words = [
        'all' => ['The floor', 'Everything that fits you, best first.'],
        'trade' => ['Trade', 'Things to buy, sell and swap.'],
        'work' => ['Work', 'Roles and people, matched on skills.'],
        'local' => ['Local', 'Near you first.'],
        'learn' => ['Learn', 'Questions, events and news that fit your interests.'],
    ];
    [$heading, $line] = $words[$mode] ?? $words['all'];
@endphp
<x-app-layout :title="$heading" width="floor" :dial="true" description="Dot.Pulse. Introductions that say why.">
    {{-- The Floor itself is where people arrive, so it opens with the arrival (logo, what this is, the first thing to do). The four mode pages keep their plain heading. --}}
    @if ($mode === 'all')
        <x-pulse.arrival />
    @else
        <x-slot name="header">
            <div>
                <h1 class="fl-h1">{{ $heading }}</h1>
                <p>{{ $line }}</p>
            </div>
        </x-slot>
    @endif

    <x-slot name="context"><livewire:pulse.floor.context /></x-slot>

    <livewire:pulse.floor.round :mode="$mode" :arrival="$mode === 'all'" :key="'round-'.$mode" />
    <livewire:pulse.knock-sheet :bare="true" />
</x-app-layout>
