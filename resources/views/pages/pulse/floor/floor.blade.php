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
    <x-slot name="header">
        <div>
            <h1 class="fl-h1">{{ $heading }}</h1>
            <p>{{ $line }}</p>
        </div>
    </x-slot>

    <x-slot name="context"><livewire:pulse.floor.context /></x-slot>

    <livewire:pulse.floor.round :mode="$mode" :key="'round-'.$mode" />
    <livewire:pulse.knock-sheet :bare="true" />
</x-app-layout>
