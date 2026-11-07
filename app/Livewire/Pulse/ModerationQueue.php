<?php

namespace App\Livewire\Pulse;

use App\Models\PulseComment;
use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseMessage;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulsePost;
use App\Models\PulseReport;
use App\Models\PulseReview;
use App\Models\PulseRole;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\ModerationActions;
use App\Services\Pulse\Operator\ModerationDesk;
use App\Services\Pulse\Operator\ReportedSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * One queue, four tabs. Posts (held for review), Listings (held offers, roles, needs and business pages), Reports
 * (about posts, comments, people, pages and listings) and Messages and reviews (reports about lines, messages and
 * reviews, plus held reviews).
 *
 * Scope: platform moderators (PulseProfile.role) see and act on everything; a community's moderators see and act
 * only on that community's held posts and reports, and never on listings, lines or reviews. Every action
 * re-authorizes against the specific thing, never just "is a moderator". A platform moderator closes reports through
 * ReportService::resolve (via ModerationDesk), so a confirmed spam report shrinks the sender's knock budget.
 */
class ModerationQueue extends Component
{
    /** Short names the view sends, and the model classes they stand for. */
    private const TYPES = [
        'post' => PulsePost::class,
        'comment' => PulseComment::class,
        'user' => User::class,
        'entity' => PulseEntity::class,
        'offer' => PulseOffer::class,
        'role' => PulseRole::class,
        'need' => PulseNeed::class,
        'signal' => PulseSignal::class,
        'message' => PulseMessage::class,
        'conversation' => PulseConversation::class,
        'review' => PulseReview::class,
    ];

    /** Reported things removed by a reasoned listing decision. */
    private const LISTING_CLASSES = [PulseEntity::class, PulseOffer::class, PulseRole::class, PulseNeed::class];

    /** 'held' (posts), 'listings', 'reports' or 'lines' (messages and reviews). */
    public string $tab = 'held';

    public ?int $rejectingPostId = null;

    public string $rejectReason = '';

    public ?string $resolvingType = null;

    public ?int $resolvingId = null;

    public string $resolveReason = '';

    /** The listing being held or removed: "offer:12", with the decision and the reason the author will read. */
    public ?string $decidingKey = null;

    public ?string $decidingDecision = null;

    public string $listingReason = '';

    /** The held review being removed. */
    public ?int $removingReviewId = null;

    public string $reviewReason = '';

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorize('moderate', PulsePost::class);
    }

    private function moderator(): User
    {
        return auth()->user();
    }

    private function desk(): ModerationDesk
    {
        return app(ModerationDesk::class);
    }

    private function isPlatform(): bool
    {
        return $this->moderator()->isPlatformModerator();
    }

    // -- Counts for the tabs ----------------------------------------------------------------------------------

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

    /** Why each held post is here, by post id: the rules' flags, re-run now. @return array<int, list<array<string, string>>> */
    #[Computed]
    public function postFlags(): array
    {
        return $this->heldPosts->mapWithKeys(fn (PulsePost $p) => [$p->id => $this->desk()->postFlags($p)])->all();
    }

    /** Held listings with the reason each is here. Platform moderators only. @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function listings(): Collection
    {
        if (! $this->isPlatform()) {
            return collect();
        }

        return $this->desk()->listings()->map(fn (array $item) => $item + ['why' => $this->desk()->why($item['model'], $item['author'])]);
    }

    #[Computed]
    public function heldReviews(): Collection
    {
        return $this->isPlatform() ? $this->desk()->heldReviews() : collect();
    }

    /**
     * Open reports grouped per reported thing: one row per post, comment, profile, page, listing, line, message or
     * review with how many people reported it and why. Reporter identities are never loaded into the view.
     *
     * @return Collection<int, array{key: string, type: string, slug: string, id: int, target: ?Model, count: int, reasons: array<string,int>, notes: list<string>, community: ?string, private: bool, first_at: mixed}>
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
                    'first_at' => $group->min('created_at'),
                ];
            })
            ->filter(fn (array $g) => $g['slug'] !== false)
            ->sortByDesc('count')
            ->values();
    }

    /** Report groups split between the Reports tab and the Messages and reviews tab. */
    private function groupsFor(bool $lines): Collection
    {
        return $this->reportGroups()->filter(fn (array $g) => in_array($g['type'], ModerationDesk::LINE_TYPES, true) === $lines)->values();
    }

    #[Computed]
    public function reportCount(): int
    {
        return $this->groupsFor(false)->count();
    }

    #[Computed]
    public function lineCount(): int
    {
        return $this->groupsFor(true)->count() + $this->heldReviews->count();
    }

    // -- Held posts -------------------------------------------------------------------------------------------

    public function approve(int $postId): void
    {
        $post = $this->heldPost($postId);

        if ($post && app(ModerationActions::class)->approvePost($post, $this->moderator())) {
            $this->desk()->postDecided($this->moderator(), $post, true);
            unset($this->heldPosts, $this->postFlags);
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
            $this->addError('rejectReason', 'Say why. The author will read it.');

            return;
        }

        if ($post && app(ModerationActions::class)->rejectPost($post, $this->moderator(), $this->rejectReason)) {
            $this->desk()->postDecided($this->moderator(), $post, false, trim($this->rejectReason));
            $this->rejectingPostId = null;
            $this->rejectReason = '';
            unset($this->heldPosts, $this->postFlags);
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

    // -- Held listings ----------------------------------------------------------------------------------------

    public function approveListing(string $key): void
    {
        $this->decide($key, 'approved', null);
    }

    public function promptListing(string $key, string $decision): void
    {
        $this->listingFor($key);
        abort_unless(in_array($decision, ['held', 'removed'], true), 422);

        $this->decidingKey = $key;
        $this->decidingDecision = $decision;
        $this->listingReason = '';
        $this->resetErrorBag();
    }

    public function cancelListing(): void
    {
        $this->decidingKey = null;
        $this->decidingDecision = null;
        $this->listingReason = '';
        $this->resetErrorBag();
    }

    public function confirmListing(): void
    {
        if ($this->decidingKey === null || $this->decidingDecision === null) {
            return;
        }

        if ($this->decide($this->decidingKey, $this->decidingDecision, $this->listingReason)) {
            $this->cancelListing();
        }
    }

    /** Approve every held listing the rules would let through today. */
    public function approveClean(): void
    {
        abort_unless($this->isPlatform(), 403);

        $count = $this->desk()->approveClean($this->moderator());
        $this->notice = $count === 0
            ? 'Nothing was clean enough to approve in one go. Look at each one.'
            : $count.' approved. Their authors have been told.';
        unset($this->listings);
    }

    private function decide(string $key, string $decision, ?string $reason): bool
    {
        $subject = $this->listingFor($key);

        try {
            $this->desk()->decideListing($this->moderator(), $subject, $decision, $reason);
        } catch (ValidationException $e) {
            $this->addError('listingReason', $e->errors()['reason'][0] ?? 'Say why.');

            return false;
        }

        $this->notice = null;
        unset($this->listings);

        return true;
    }

    /** The listing behind a "type:id" key; platform moderators only, 404 when it is gone. */
    private function listingFor(string $key): Model
    {
        abort_unless($this->isPlatform(), 403);

        [$type, $id] = array_pad(explode(':', $key, 2), 2, null);
        $class = ModerationDesk::LISTING_TYPES[$type] ?? null;

        abort_if($class === null || ! ctype_digit((string) $id), 404);

        return $class::query()->findOrFail((int) $id);
    }

    // -- Reports ----------------------------------------------------------------------------------------------

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

    /**
     * The main action on a reported thing. Posts and comments are removed, listings and pages are removed through the
     * listing decision, a review is removed, and a person, line, message or listing signal is marked reviewed (for a
     * person, or confirmed for the rest, which counts: confirmed spam shrinks the sender's knock budget).
     */
    public function resolveReports(string $type, int $id): void
    {
        $target = $this->reportTarget($type, $id);
        $platform = $this->isPlatform();

        $needsReason = $target instanceof PulsePost || $target instanceof PulseComment || $target instanceof PulseReview
            || in_array($target::class, self::LISTING_CLASSES, true);

        if ($needsReason && trim($this->resolveReason) === '') {
            $this->addError('resolveReason', 'Say why this is being removed. It is kept in the moderation log.');

            return;
        }

        $reason = $needsReason ? trim($this->resolveReason) : null;

        if (! $platform) {
            app(ModerationActions::class)->resolveReports($target, $this->moderator(), $reason);
            $this->cancelResolve();

            return;
        }

        match (true) {
            $target instanceof PulsePost => $this->removeContent($target, $reason, fn () => app(ModerationActions::class)->removePost($target, $this->moderator(), (string) $reason)),
            $target instanceof PulseComment => $this->removeContent($target, $reason, fn () => app(ModerationActions::class)->removeComment($target, $this->moderator(), (string) $reason)),
            in_array($target::class, self::LISTING_CLASSES, true) => $this->removeListing($target, (string) $reason),
            $target instanceof PulseReview => $this->removeReview($target, (string) $reason),
            $target instanceof User => app(ModerationActions::class)->resolveReports($target, $this->moderator(), null),
            default => $this->desk()->closeReports($this->moderator(), $target, true, 'reviewed'),
        };

        $this->cancelResolve();
    }

    /** Confirm the report as true without removing anything (a person, a line, a message). */
    public function upholdReports(string $type, int $id): void
    {
        $target = $this->reportTarget($type, $id);
        abort_unless($this->isPlatform(), 403);

        $this->desk()->closeReports($this->moderator(), $target, true, 'reviewed');
        $this->cancelResolve();
    }

    public function dismissReports(string $type, int $id): void
    {
        $target = $this->reportTarget($type, $id);

        if ($this->isPlatform()) {
            $this->desk()->closeReports($this->moderator(), $target, false);
        } else {
            app(ModerationActions::class)->dismissReports($target, $this->moderator());
        }

        $this->cancelResolve();
    }

    /** Close the reports first (so each one goes through ReportService), then remove the content. */
    private function removeContent(Model $target, ?string $reason, \Closure $remove): void
    {
        $this->desk()->closeReports($this->moderator(), $target, true, 'removed');
        $remove();
    }

    private function removeListing(Model $target, string $reason): void
    {
        $this->desk()->decideListing($this->moderator(), $target, 'removed', $reason);
        $this->desk()->closeReports($this->moderator(), $target, true, 'removed');
        unset($this->listings);
    }

    private function removeReview(PulseReview $review, string $reason): void
    {
        $this->desk()->decideReview($this->moderator(), $review, false, $reason);
        $this->desk()->closeReports($this->moderator(), $review, true, 'removed');
    }

    /**
     * Resolve a client-supplied (class, id) pair to a reportable model this moderator is allowed to act on. Only the
     * reportable classes are accepted; anything else is a 404, anything out of scope a 403. Business-side things
     * (pages, listings, lines, messages, reviews) are for platform moderators only.
     */
    private function reportTarget(string $type, int $id): Model
    {
        $type = self::TYPES[$type] ?? $type;

        abort_unless(in_array($type, self::TYPES, true), 404);

        $target = match ($type) {
            PulsePost::class => PulsePost::withTrashed()->with('community')->find($id),
            PulseComment::class => PulseComment::withTrashed()->with('post.community')->find($id),
            User::class => User::find($id),
            PulseEntity::class, PulseOffer::class, PulseRole::class, PulseNeed::class => $type::withTrashed()->find($id),
            PulseSignal::class, PulseMessage::class, PulseConversation::class, PulseReview::class => $type::query()->find($id),
        };

        abort_if($target === null, 404);

        if ($target instanceof PulsePost || $target instanceof PulseComment) {
            Gate::authorize('moderate', $target instanceof PulseComment ? $target->post : $target);
        } else {
            abort_unless($this->moderator()->isPlatformModerator(), 403);
        }

        return $target;
    }

    // -- Held reviews -----------------------------------------------------------------------------------------

    public function approveReview(int $reviewId): void
    {
        abort_unless($this->isPlatform(), 403);

        $this->desk()->decideReview($this->moderator(), PulseReview::query()->where('moderation', 'held')->findOrFail($reviewId), true);
        unset($this->heldReviews);
    }

    public function promptRemoveReview(int $reviewId): void
    {
        abort_unless($this->isPlatform(), 403);

        $this->removingReviewId = $reviewId;
        $this->reviewReason = '';
    }

    public function cancelRemoveReview(): void
    {
        $this->removingReviewId = null;
        $this->reviewReason = '';
    }

    public function confirmRemoveReview(): void
    {
        abort_unless($this->isPlatform(), 403);

        if ($this->removingReviewId === null) {
            return;
        }

        if (trim($this->reviewReason) === '') {
            $this->addError('reviewReason', 'Say why. The writer will read it.');

            return;
        }

        $review = PulseReview::query()->where('moderation', 'held')->findOrFail($this->removingReviewId);
        $this->desk()->decideReview($this->moderator(), $review, false, $this->reviewReason);
        $this->cancelRemoveReview();
        unset($this->heldReviews);
    }

    // -- Render -----------------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $group */
    public function describe(array $group): array
    {
        return ReportedSubject::describe($group['target']);
    }

    public function render(): View
    {
        return view('livewire.pulse.moderation-queue', [
            'reportGroups' => $this->tab === 'reports' ? $this->groupsFor(false) : collect(),
            'lineGroups' => $this->tab === 'lines' ? $this->groupsFor(true) : collect(),
            'platform' => $this->isPlatform(),
        ]);
    }
}
