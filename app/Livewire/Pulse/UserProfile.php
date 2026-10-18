<?php

namespace App\Livewire\Pulse;

use App\Models\PulsePost;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The follow graph's missing UI (see User::followers()/following(), added
 * alongside this) — this is also the first public profile page in the
 * app; PulseProfile (headline/bio/skills/expertise_tags) existed with
 * nowhere to display it.
 */
class UserProfile extends Component
{
    public User $profileUser;

    public function mount(User $profileUser): void
    {
        $this->profileUser = $profileUser;
    }

    #[Computed]
    public function posts(): Collection
    {
        return PulsePost::where('user_id', $this->profileUser->id)
            ->published()
            ->visibleTo(auth()->user())
            ->with('community')
            ->latest()
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function followersCount(): int
    {
        return $this->profileUser->followers()->count();
    }

    #[Computed]
    public function followingCount(): int
    {
        return $this->profileUser->following()->count();
    }

    #[Computed]
    public function isOwnProfile(): bool
    {
        return auth()->id() === $this->profileUser->id;
    }

    /**
     * FollowButton (embedded in the view in place of the old inline
     * button) owns follow/unfollow now and dispatches this browser event
     * on every toggle -- the same sibling-component-talks-via-event shape
     * TrendingHashtags uses for filter-by-hashtag.
     */
    #[On('follow-toggled')]
    public function refreshCounts(): void
    {
        unset($this->followersCount, $this->followingCount);
    }

    public function render(): View
    {
        return view('livewire.pulse.user-profile');
    }
}
