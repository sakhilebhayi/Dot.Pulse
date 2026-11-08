<?php

namespace App\Livewire\Pulse;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Shown only in HomeFeed's empty Following-tab state (see
 * resources/views/livewire/pulse/home-feed.blade.php). Sorting in PHP
 * after a full fetch, rather than a DB-level join/orderBy on a related
 * table's column, is deliberately simple and matches the data volumes
 * this app currently runs at -- the same pragmatic choice already made
 * in App\Services\AiModerationService's synchronous, non-queued Claude
 * calls. Worth revisiting with a real query if the users table grows
 * large enough for this to matter.
 */
class SuggestedUsers extends Component
{
    #[Computed]
    public function suggestions(): Collection
    {
        $followingIds = auth()->user()->following()->pluck('users.id');

        return User::query()
            ->whereKeyNot(auth()->id())
            ->whereNotIn('id', $followingIds)
            // An anonymous professional is never suggested: they have no page to open and no name to show.
            ->whereDoesntHave('pulseProfile', fn ($q) => $q->where('discoverability', 'anonymous'))
            ->with('pulseProfile')
            ->get()
            ->sortByDesc(fn (User $u) => $u->pulseProfile?->community_points ?? 0)
            ->take(5)
            ->values();
    }

    public function render(): View
    {
        return view('livewire.pulse.suggested-users');
    }
}
