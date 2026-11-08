<x-app-layout title="Community" width="floor" :dial="false">
    <x-slot name="header">
        <div>
            <h1 class="lg-pagetitle">Community</h1>
            <p>What people across the ecosystem are saying, asking and answering.</p>
        </div>
    </x-slot>

    <x-slot name="context">
        <livewire:pulse.trending-hashtags />
        @if ($myPoints > 0)
            <div class="pk-card lg-mine">
                <h2 class="lg-h">Your points</h2>
                <p><span class="pk-mono">{{ number_format($myPoints) }}</span></p>
            </div>
        @endif
    </x-slot>

    <div class="pk-stack">
        <livewire:pulse.create-post />
        <livewire:pulse.my-post-status />
        <livewire:pulse.home-feed />
        <livewire:pulse.community-list />
    </div>
</x-app-layout>
