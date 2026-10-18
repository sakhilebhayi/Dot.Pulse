<?php

namespace App\Livewire\Pulse;

use App\Models\Community;
use App\Models\CommunityJoinRequest;
use App\Services\CommunityMembershipService;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The directory. Public communities are listed in full. Private ones are
 * listed by name and industry only (that is how a person finds one to ask
 * to join) -- no description, no link to the page, and no posts.
 */
class CommunityList extends Component
{
    public string $search = '';

    #[Computed]
    public function communities(): Collection
    {
        return Community::query()
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('industry', 'like', '%'.$this->search.'%')
            ))
            ->orderByDesc('members_count')
            ->limit(24)
            ->get();
    }

    /** @return array<int, true> ids of communities the viewer belongs to */
    #[Computed]
    public function memberOf(): array
    {
        return auth()->user()->joinedCommunities()->pluck('communities.id')->flip()->map(fn () => true)->all();
    }

    /** @return array<int, string> community id => request status */
    #[Computed]
    public function requests(): array
    {
        return CommunityJoinRequest::where('user_id', auth()->id())->pluck('status', 'community_id')->all();
    }

    /** @return array<int, true> ids where a recent denial blocks asking again */
    #[Computed]
    public function coolingDown(): array
    {
        $since = now()->subDays(config('pulse.join_request_cooldown_days'));

        return CommunityJoinRequest::where('user_id', auth()->id())
            ->where('status', CommunityJoinRequest::DENIED)
            ->where('decided_at', '>', $since)
            ->pluck('community_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    public function join(int $communityId): void
    {
        $community = Community::findOrFail($communityId);

        app(CommunityMembershipService::class)->join(auth()->user(), $community);

        $this->refreshLists();
    }

    public function cancelRequest(int $communityId): void
    {
        $community = Community::findOrFail($communityId);

        app(CommunityMembershipService::class)->cancelRequest(auth()->user(), $community);

        $this->refreshLists();
    }

    public function leave(int $communityId): void
    {
        $community = Community::findOrFail($communityId);

        $community->removeMember(auth()->user());

        $this->refreshLists();
    }

    private function refreshLists(): void
    {
        unset($this->communities, $this->memberOf, $this->requests, $this->coolingDown);
    }

    public function render(): View
    {
        return view('livewire.pulse.community-list');
    }
}
