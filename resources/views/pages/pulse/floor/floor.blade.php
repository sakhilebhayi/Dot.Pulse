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
<x-app-layout :title="$heading" width="desk" :dial="true" description="Dot.Pulse. Introductions that say why.">
    {{-- The Floor itself is where people arrive: its arrival (logo, a true headline about this round, the first thing to do) is drawn by the round, which knows the counts. The four mode pages keep their plain heading. --}}
    @if ($mode !== 'all')
        <x-slot name="header">
            <div>
                <h1 class="fl-h1">{{ $heading }}</h1>
                <p>{{ $line }}</p>
            </div>
        </x-slot>
    @endif

    <livewire:pulse.floor.round :mode="$mode" :arrival="$mode === 'all'" :key="'round-'.$mode" />
    <livewire:pulse.floor.context />
    <footer class="fl-foot" data-floor-foot>
        <span><kbd>n</kbd> next beat <kbd>p</kbd> previous <kbd>s</kbd> save <kbd>Enter</kbd> open</span>
    </footer>
    <livewire:pulse.knock-sheet :bare="true" />
</x-app-layout>
