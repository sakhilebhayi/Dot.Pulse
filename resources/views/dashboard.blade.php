<x-app-layout>

<div class="pulse-page">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.2rem;letter-spacing:-0.01em;">Community Feed</h1>
            <p style="font-size:0.78rem;color:#52525b;margin:0;">What's happening across the ecosystem</p>
        </div>
    </div>

    {{-- KPI Strip --}}
    <div class="kpi-grid">
        <div class="dot-card kpi-card">
            <div class="kpi-label">Total Posts</div>
            <div class="metric-val kpi-value">{{ $totalPosts }}</div>
        </div>
        <div class="dot-card kpi-card">
            <div class="kpi-label">Communities</div>
            <div class="metric-val kpi-value">{{ $totalCommunities }}</div>
        </div>
        <div class="dot-card kpi-card">
            <div class="kpi-label">My Points</div>
            <div class="metric-val kpi-value">{{ $myPoints }}</div>
        </div>
    </div>

    {{-- Create Post --}}
    <livewire:pulse.create-post />
    <livewire:pulse.my-post-status />

    <div class="feed-grid">
        <div>
            {{-- Feed --}}
            <livewire:pulse.home-feed />

            {{-- Communities --}}
            <div style="margin-top:1.5rem;">
                <livewire:pulse.community-list />
            </div>
        </div>

        {{-- Trending --}}
        <livewire:pulse.trending-hashtags />
    </div>

</div>

</x-app-layout>
