<?php

namespace App\Livewire\Pulse;

use App\Models\PulseComment;
use App\Models\PulsePost;
use App\Models\PulseReport;
use App\Models\User;
use App\Services\ModerationActions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Two lists, one scope. Platform moderators (PulseProfile.role) see and act
 * on everything; a community's moderators see and act only on that
 * community's held posts and reports. Every action re-authorizes against the
 * specific post, comment or profile, never just "is a moderator".
 */
class ModerationQueue extends Component
{
    /** Short names the view sends, and the model classes they stand for. */
    private const TYPES = [
        'post' => PulsePost::class,
        'comment' => PulseComment::class,
        'user' => User::class,
    ];

    /** 'held' (posts waiting for review) or 'reports' (user reports). */
    public string $tab = 'held';

    public ?int $rejectingPostId = null;

    public string $rejectReason = '';

    public ?string $resolvingType = null;

    public ?int $resolvingId = null;

    public string $resolveReason = '';

    public function mount(): void
    {
        $this->authorize('moderate', PulsePost::class);
    }

    private function moderator(): User
    {
        return auth()->user();
    }

    #[Computed]
    public function heldPosts(): Collection
    {
        $moderator = $this->moderator();

        return PulsePost::where('status', 'flagged')
            ->when(! $moderator->isPlatformModerator(), fn ($q) => $q->whereIn('community_id', $moderator->managedCommunityIds()))
            ->with(['enrichment', 'author', 'community'])
            ->latest()
            ->get();
    }

    /**
     * Open reports grouped per reported thing: one row per post, comment or
     * profile with how many people reported it and why. Reporter identities
     * are never loaded into the view.
     *
     * @return Collection<int, array{key: string, type: string, slug: string, id: int, target: ?Model, count: int, reasons: array<string,int>, notes: list<string>, community: ?string, private: bool}>
     */
    public function reportGroups(): Collection
    {
        $reports = PulseReport::open()
            ->moderatableBy($this->moderator())
            ->with(['reportable', 'community'])
            ->latest()
            ->limit(500)
            ->get();

        return $reports
            ->groupBy(fn (PulseReport $r) => $r->reportable_type.'|'.$r->reportable_id)
            ->map(function (Collection $group, string $key) {
                $first = $group->first();

                return [
                    'key' => $key,
                    'type' => $first->reportable_type,
                    'slug' => array_search($first->reportable_type, self::TYPES, true),
                    'id' => (int) $first->reportable_id,
                    'target' => $first->reportable,
                    'count' => $group->count(),
                    'reasons' => $group->countBy('reason')->all(),
                    'notes' => $group->pluck('details')->filter()->values()->all(),
                    'community' => $first->community?->name,
                    'private' => (bool) $first->community?->isPrivate(),
                ];
            })
            ->sortByDesc('count')
            ->values();
    }

    #[Computed]
    public function reportCount(): int
    {
        return $this->reportGroups()->count();
    }

    // -- Held posts -------------------------------------------------------

    public function approve(int $postId): void
    {
        $post = $this->heldPost($postId);

        if ($post && app(ModerationActions::class)->approvePost($post, $this->moderator())) {
            unset($this->heldPosts);
        }
    }

    public function promptReject(int $postId): void
    {
        $this->heldPost($postId);

        $this->rejectingPostId = $postId;
        $this->rejectReason = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingPostId = null;
        $this->rejectReason = '';
    }

    public function confirmReject(int $postId): void
    {
        $post = $this->heldPost($postId);

        if (trim($this->rejectReason) === '') {
            return;
        }

        if ($post && app(ModerationActions::class)->rejectPost($post, $this->moderator(), $this->rejectReason)) {
            $this->rejectingPostId = null;
            $this->rejectReason = '';
            unset($this->heldPosts);
        }
    }

    /** A flagged post this moderator is allowed to act on, else 403; gone or decided, null. */
    private function heldPost(int $postId): ?PulsePost
    {
        $post = PulsePost::with('community')->find($postId);

        if ($post === null) {
            return null;
        }

        Gate::authorize('moderate', $post);

        return $post->status === 'flagged' ? $post : null;
    }

    // -- Reports ----------------------------------------------------------

    public function promptResolve(string $type, int $id): void
    {
        $this->reportTarget($type, $id);

        $this->resolvingType = self::TYPES[$type] ?? $type;
        $this->resolvingId = $id;
        $this->resolveReason = '';
    }

    public function cancelResolve(): void
    {
        $this->resolvingType = null;
        $this->resolvingId = null;
        $this->resolveReason = '';
    }

    public function resolveReports(string $type, int $id): void
    {
        $target = $this->reportTarget($type, $id);

        $needsReason = $target instanceof PulsePost || $target instanceof PulseComment;

        if ($needsReason && trim($this->resolveReason) === '') {
            $this->addError('resolveReason', 'Say why this is being removed. It is kept in the moderation log.');

            return;
        }

        app(ModerationActions::class)->resolveReports($target, $this->moderator(), $needsReason ? $this->resolveReason : null);

        $this->cancelResolve();
    }

    public function dismissReports(string $type, int $id): void
    {
        $target = $this->reportTarget($type, $id);

        app(ModerationActions::class)->dismissReports($target, $this->moderator());

        $this->cancelResolve();
    }

    /**
     * Resolve a client-supplied (class, id) pair to a reportable model this
     * moderator is allowed to act on. Only the three reportable classes are
     * accepted; anything else is a 404, anything out of scope a 403.
     */
    private function reportTarget(string $type, int $id): Model
    {
        $type = self::TYPES[$type] ?? $type;

        abort_unless(in_array($type, self::TYPES, true), 404);

        $target = match ($type) {
            PulsePost::class => PulsePost::withTrashed()->with('community')->find($id),
            PulseComment::class => PulseComment::withTrashed()->with('post.community')->find($id),
            User::class => User::find($id),
        };

        abort_if($target === null, 404);

        if ($target instanceof User) {
            abort_unless($this->moderator()->isPlatformModerator(), 403);
        } else {
            Gate::authorize('moderate', $target instanceof PulseComment ? $target->post : $target);
        }

        return $target;
    }

    public function render(): View
    {
        return view('livewire.pulse.moderation-queue', [
            'reportGroups' => $this->tab === 'reports' ? $this->reportGroups() : collect(),
        ]);
    }
}
