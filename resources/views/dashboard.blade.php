<x-app-layout title="Community" width="floor" :dial="false">
    <x-slot name="header">
        <div>
            <h1 class="lg-pagetitle">Community</h1>
            <p>What people across the ecosystem are saying, asking and answering.</p>
        </div>
    </x-slot>

    <x-slot name="context">
        <livewire:pulse.trending-hashtags />
        <div class="pk-card lg-mine">
            <h2 class="lg-h">Your standing</h2>
            <dl class="lg-facts">
                <div><dt>Posts on the platform</dt><dd>{{ number_format($totalPosts) }}</dd></div>
                <div><dt>Communities</dt><dd>{{ number_format($totalCommunities) }}</dd></div>
                <div><dt>Your points</dt><dd>{{ number_format($myPoints) }}</dd></div>
            </dl>
        </div>
    </x-slot>

    <div class="pk-stack">
        <livewire:pulse.create-post />
        <livewire:pulse.my-post-status />
        <livewire:pulse.home-feed />
        <livewire:pulse.community-list />
    </div>
</x-app-layout>
