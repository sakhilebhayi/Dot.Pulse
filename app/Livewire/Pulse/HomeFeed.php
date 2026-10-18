<?php

namespace App\Livewire\Pulse;

use App\Models\PulsePost;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class HomeFeed extends Component
{
    public string $filterType = '';

    public bool $followingOnly = false;

    public string $hashtagFilter = '';

    #[Computed]
    public function posts(): Collection
    {
        return PulsePost::with(['author', 'community', 'enrichment'])
            ->published()
            // The viewer's own view of the world: public posts, posts with
            // no community, and posts from communities they belong to.
            ->visibleTo(auth()->user())
            ->when($this->filterType, fn ($q) => $q->where('type', $this->filterType))
            ->when($this->followingOnly, function ($q) {
                $followingIds = auth()->user()->following()->pluck('users.id');
                $communityIds = auth()->user()->joinedCommunities()->pluck('communities.id');

                $q->where(function ($q2) use ($followingIds, $communityIds) {
                    $q2->whereIn('user_id', $followingIds)
                        ->orWhereIn('community_id', $communityIds);
                });
            })
            ->when($this->hashtagFilter, fn ($q) => $q->whereHas(
                'hashtags',
                fn ($q2) => $q2->where('name', $this->hashtagFilter),
            ))
            ->orderByDesc('ai_relevance_score')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get();
    }

    #[On('post-created')]
    public function refresh(): void
    {
        unset($this->posts);
    }

    /**
     * TrendingHashtags::filterByHashtag() dispatches this cross-component
     * event -- the sidebar widget and the feed itself are siblings, not
     * parent/child, so a browser event is how they talk.
     */
    #[On('filter-by-hashtag')]
    public function filterByHashtag(string $name): void
    {
        $this->hashtagFilter = $name;
        unset($this->posts);
    }

    public function clearHashtagFilter(): void
    {
        $this->hashtagFilter = '';
        unset($this->posts);
    }

    /**
     * Only a published post the viewer can see can be reacted to; anything
     * else (held, removed, pending, in a private community they are not in)
     * is indistinguishable from a post that does not exist.
     */
    public function react(int $postId, string $emoji = '👍'): void
    {
        $user = auth()->user();

        abort_unless(in_array($emoji, config('pulse.reaction_emojis'), true), 422, 'Unsupported reaction.');

        $post = PulsePost::published()->visibleTo($user)->find($postId);
        abort_if($post === null, 404);
        Gate::authorize('react', $post);

        $post->toggleReaction($user, $emoji);

        unset($this->posts);
    }

    public function render(): View
    {
        return view('livewire.pulse.home-feed');
    }
}
