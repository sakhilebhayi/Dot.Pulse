<?php

namespace App\Livewire\Pulse;

use App\Models\PulseHashtag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * README advertises "trending topics and discovery feed" -- PulseHashtag
 * existed since the platform's first migration, referenced nowhere but
 * its own model file, until App\Services\HashtagExtractionService started
 * populating it. "Trending" is computed live (posts in the last 7 days),
 * not PulseHashtag.posts_count (an all-time total, useful for a different
 * "all-time popular" view this doesn't build).
 */
class TrendingHashtags extends Component
{
    #[Computed]
    public function trending(): Collection
    {
        // Counts only posts this viewer can see, so a private community's
        // hashtags neither appear nor inflate a public tag's number.
        $viewer = auth()->user();

        $recentWindow = function ($query) use ($viewer) {
            $query->where('status', 'published')
                ->whereNull('pulse_posts.deleted_at')
                ->where('pulse_posts.created_at', '>=', now()->subDays(7))
                ->visibleTo($viewer);
        };

        return PulseHashtag::query()
            // whereHas (an EXISTS subquery) rather than a HAVING clause on
            // withCount's derived column -- sqlite (this repo's test
            // driver) rejects a bare HAVING on a subquery alias with no
            // GROUP BY as "HAVING clause on a non-aggregate query".
            ->whereHas('posts', $recentWindow)
            ->withCount(['posts as recent_posts_count' => $recentWindow])
            ->orderByDesc('recent_posts_count')
            ->limit(10)
            ->get();
    }

    public function filterByHashtag(string $name): void
    {
        $this->dispatch('filter-by-hashtag', name: $name);
    }

    public function render(): View
    {
        return view('livewire.pulse.trending-hashtags');
    }
}
