<?php

namespace App\Livewire\Pulse;

use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\User;
use App\Services\Pulse\Entities\ProfileService;
use App\Services\Pulse\Lines\ContactGate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A person's public page (/u/{id}), in the Floor system.
 *
 * What it shows depends on the discoverability the person chose (ProfileService decides the content):
 *  - anonymous: the page does not exist for anyone but the person. It never resolves to a name, a photo or an email;
 *    the response is the same 404 an unknown id gets.
 *  - open: name, photo, headline, about, skills, languages, work and the rest of the card.
 *  - off: name, their published posts, Follow, and a Knock when their contact policy allows one. Nothing else.
 * The person themselves always sees their own full page.
 */
class UserProfile extends Component
{
    public User $profileUser;

    public function mount(User $profileUser): void
    {
        $this->profileUser = $profileUser;

        $mode = PulseProfile::query()->where('user_id', $profileUser->id)->value('discoverability');

        if ($mode === 'anonymous' && auth()->id() !== $profileUser->id) {
            abort(404);
        }
    }

    #[Computed]
    public function profile(): ?PulseProfile
    {
        return PulseProfile::query()->where('user_id', $this->profileUser->id)->first();
    }

    /**
     * The card for this viewer, only when the page may show professional details: the person themselves, or an open
     * profile whose owner is an adult who confirmed it.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function card(): ?array
    {
        $profile = $this->profile;

        if ($profile === null || (! $this->isOwnProfile && ! ($profile->discoverability === 'open' && $profile->isAdultConfirmed()))) {
            return null;
        }

        return app(ProfileService::class)->card($profile, auth()->user());
    }

    /** @return array{allowed: bool, mode: string, why: string, code: string, stranger: bool}|null */
    #[Computed]
    public function contact(): ?array
    {
        if ($this->isOwnProfile || auth()->user() === null) {
            return null;
        }

        return app(ContactGate::class)->can(auth()->user(), $this->profileUser);
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
     * FollowButton owns follow/unfollow and dispatches this browser event on every toggle.
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
