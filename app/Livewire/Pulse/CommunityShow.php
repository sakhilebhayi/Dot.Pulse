<?php

namespace App\Livewire\Pulse;

use App\Models\Community;
use App\Models\CommunityJoinRequest;
use App\Services\CommunityMembershipService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * A community's own page. A private community's page is a 404 for anyone
 * who is not a member -- on first load and on every later action, so a
 * person removed mid-session cannot keep acting on a stale tab.
 */
class CommunityShow extends Component
{
    public Community $community;

    public function mount(Community $community): void
    {
        $this->community = $community;
        $this->guardVisible();
    }

    private function guardVisible(): void
    {
        abort_unless(Gate::allows('view', $this->community), 404);
    }

    #[Computed]
    public function posts(): Collection
    {
        return $this->community->posts()
            ->published()
            ->visibleTo(auth()->user())
            ->with(['author', 'enrichment'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get();
    }

    #[Computed]
    public function isMember(): bool
    {
        return $this->community->isMember(auth()->user());
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manageMembers', $this->community);
    }

    #[Computed]
    public function pendingRequests(): Collection
    {
        if (! $this->canManage) {
            return new Collection;
        }

        return $this->community->joinRequests()
            ->where('status', CommunityJoinRequest::PENDING)
            ->with('user')
            ->oldest()
            ->get();
    }

    public function join(): void
    {
        $this->guardVisible();

        app(CommunityMembershipService::class)->join(auth()->user(), $this->community);

        $this->community->refresh();
        unset($this->isMember);
    }

    public function leave(): void
    {
        $this->guardVisible();

        $this->community->removeMember(auth()->user());

        $this->community->refresh();
        unset($this->isMember, $this->canManage, $this->pendingRequests);

        // Leaving a private community removes the page from under you.
        if (! Gate::allows('view', $this->community)) {
            $this->redirectRoute('dashboard');
        }
    }

    public function approveRequest(int $requestId): void
    {
        $this->decide($requestId, true);
    }

    public function denyRequest(int $requestId): void
    {
        $this->decide($requestId, false);
    }

    private function decide(int $requestId, bool $approve): void
    {
        $this->guardVisible();
        Gate::authorize('manageMembers', $this->community);

        $request = $this->community->joinRequests()
            ->where('status', CommunityJoinRequest::PENDING)
            ->with(['user', 'community'])
            ->find($requestId);

        abort_if($request === null, 404);

        $service = app(CommunityMembershipService::class);
        $approve ? $service->approve($request, auth()->user()) : $service->deny($request, auth()->user());

        $this->community->refresh();
        unset($this->pendingRequests);
    }

    public function render(): View
    {
        return view('livewire.pulse.community-show');
    }
}
