<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;max-width:1100px;">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.2rem;letter-spacing:-0.01em;">Community Feed</h1>
            <p style="font-size:0.78rem;color:#52525b;margin:0;">What's happening across the ecosystem</p>
        </div>
    </div>

    {{-- KPI Strip --}}
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:2rem;">
        <div class="dot-card" style="padding:1.25rem 1.5rem;">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.75rem;">Total Posts</div>
            <div class="metric-val" style="font-size:2rem;font-weight:600;color:var(--accent);">{{ $totalPosts }}</div>
        </div>
        <div class="dot-card" style="padding:1.25rem 1.5rem;">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.75rem;">Communities</div>
            <div class="metric-val" style="font-size:2rem;font-weight:600;color:var(--accent);">{{ $totalCommunities }}</div>
        </div>
        <div class="dot-card" style="padding:1.25rem 1.5rem;">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.75rem;">My Points</div>
            <div class="metric-val" style="font-size:2rem;font-weight:600;color:var(--accent);">{{ $myPoints }}</div>
        </div>
    </div>

    {{-- Create Post --}}
    <livewire:pulse.create-post />
    <livewire:pulse.my-post-status />

    <div style="display:grid;grid-template-columns:1fr 260px;gap:1.25rem;margin-top:1.25rem;align-items:start;">
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
