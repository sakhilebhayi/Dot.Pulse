<?php

namespace App\Services\Pulse\Operator;

use App\Models\PulseAuditEntry;
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
use App\Services\Pulse\Entities\ContentGate;
use App\Services\Pulse\Entities\ListingModeration;
use App\Services\Pulse\Lines\AuditLog;
use App\Services\Pulse\Lines\Notifier;
use App\Services\Pulse\Lines\ReportService;
use App\Services\Pulse\Lines\ReviewService;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The moderation queue's second half (R-41): held listings, reports on lines, messages and reviews, and the plain
 * words each author hears. The post queue and the report grouping that existed before R-41 stay in
 * ModerationQueue and ModerationActions; this class adds what the business side needs and never duplicates it.
 *
 * Every decision here: re-checks that the moderator is a platform moderator, is written to the moderation log and
 * the audit log by the service it calls (ListingModeration, ReportService, ReviewService), and is told to the
 * author through the Notifier in a sentence a person can read.
 */
class ModerationDesk
{
    /** Types a listing decision can be made on, as the short names the queue sends. */
    public const LISTING_TYPES = [
        'offer' => PulseOffer::class,
        'role' => PulseRole::class,
        'need' => PulseNeed::class,
        'entity' => PulseEntity::class,
    ];

    /** Reported things that belong on the Messages and reviews tab. */
    public const LINE_TYPES = [PulseMessage::class, PulseConversation::class, PulseReview::class];

    /** How many items a tab loads at once. */
    private const LIMIT = 50;

    public function __construct(
        private readonly ListingModeration $listings,
        private readonly Notifier $notifier,
        private readonly ReportService $reports,
        private readonly ReviewService $reviews,
    ) {}

    // ---------------------------------------------------------------- what is waiting

    /**
     * Listings and pages a person has to decide on, oldest first. Drafts, paused, closed and archived things are not
     * waiting: only what is live (or open) but not yet approved.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listings(): Collection
    {
        $waiting = ['held', 'pending'];

        $items = collect()
            ->merge(PulseOffer::query()->where('status', 'live')->whereIn('moderation', $waiting)->with(['entity', 'creator'])->limit(self::LIMIT)->get()->map(fn (PulseOffer $m) => $this->item('offer', $m, $m->title, $m->creator, $m->entity)))
            ->merge(PulseRole::query()->where('status', 'live')->whereIn('moderation', $waiting)->with(['entity', 'creator'])->limit(self::LIMIT)->get()->map(fn (PulseRole $m) => $this->item('role', $m, $m->title, $m->creator, $m->entity)))
            ->merge(PulseNeed::query()->where('status', 'open')->whereIn('moderation', $waiting)->with('user')->limit(self::LIMIT)->get()->map(fn (PulseNeed $m) => $this->item('need', $m, $m->title, $m->user, null)))
            ->merge(PulseEntity::query()->where('status', 'live')->whereIn('moderation', $waiting)->with('creator')->limit(self::LIMIT)->get()->map(fn (PulseEntity $m) => $this->item('entity', $m, $m->name, $m->creator, null)));

        return $items->sortBy(fn (array $i) => $i['since']->getTimestamp())->values()->take(self::LIMIT);
    }

    /** @return array<string, mixed> */
    private function item(string $type, Model $model, string $title, ?User $author, ?PulseEntity $entity): array
    {
        return [
            'key' => $type.':'.$model->getKey(),
            'type' => $type,
            'noun' => self::noun($type),
            'model' => $model,
            'title' => $title,
            'author' => $author,
            'entity' => $entity,
            'moderation' => $model->moderation,
            'since' => $model->updated_at ?? $model->created_at ?? now(),
        ];
    }

    /**
     * When each waiting thing started waiting: held posts, held listings, open reports and held reviews.
     *
     * @return Collection<int, CarbonInterface>
     */
    public function waitingSince(): Collection
    {
        $waiting = ['held', 'pending'];

        return collect()
            ->merge(PulsePost::query()->where('status', 'flagged')->pluck('created_at'))
            ->merge(PulseOffer::query()->where('status', 'live')->whereIn('moderation', $waiting)->pluck('updated_at'))
            ->merge(PulseRole::query()->where('status', 'live')->whereIn('moderation', $waiting)->pluck('updated_at'))
            ->merge(PulseNeed::query()->where('status', 'open')->whereIn('moderation', $waiting)->pluck('updated_at'))
            ->merge(PulseEntity::query()->where('status', 'live')->whereIn('moderation', $waiting)->pluck('updated_at'))
            ->merge(PulseReport::query()->open()->pluck('created_at'))
            ->merge(PulseReview::query()->where('moderation', 'held')->pluck('created_at'))
            ->filter()
            ->values();
    }

    /** Held reviews, oldest first. @return Collection<int, PulseReview> */
    public function heldReviews(): Collection
    {
        return PulseReview::query()->where('moderation', 'held')->with(['author', 'reviewable'])->oldest()->limit(self::LIMIT)->get();
    }

    // ---------------------------------------------------------------- why an item is here

    /**
     * What the deterministic rules say about this listing's text, and how it got here. The rules are re-run now:
     * they need no model and no network, and the flags are what a person is asked to weigh.
     *
     * @return array{flags: list<array{code: string, severity: string, text: string}>, source: ?string, note: string}
     */
    public function why(Model $subject, ?User $author): array
    {
        $source = $this->sourceOf($subject);

        $class = (string) (config('pulse.entities.rules_class') ?: ContentGate::LOCAL_RULES);

        // What the gate stored when it held this text is what the author was told; show that before re-running.
        $flags = (array) ($subject->moderation_flags ?? []);

        if ($flags === [] && $author !== null && class_exists($class)) {
            try {
                $flags = (array) (app($class)->assess(ListingText::of($subject), $author, ['kind' => ListingText::kind($subject)])['flags'] ?? []);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $note = match (true) {
            $flags !== [] => 'The rules flagged this text.',
            $source === 'no_rules' => 'No automatic check ran when this was submitted, so a person decides.',
            $source === 'infodot_member' => 'This came from an InfoDot company member and waits for a person.',
            default => 'The rules held it when it was submitted. The text shows no flags now.',
        };

        $flags = array_values(array_map(fn ($f) => is_array($f) ? $f : ['code' => (string) $f, 'severity' => 'hold', 'text' => ucfirst(str_replace('_', ' ', (string) $f))], $flags));

        return ['flags' => $flags, 'source' => $source, 'note' => $note];
    }

    /** How the last submit of this subject was decided (rules, no rules, InfoDot member), from the audit log. */
    private function sourceOf(Model $subject): ?string
    {
        $entry = PulseAuditEntry::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->whereIn('action', ['offer.publish', 'role.publish', 'need.create', 'need.update', 'entity.create', 'entity.update'])
            ->latest('id')
            ->first();

        return is_array($entry?->meta) ? ($entry->meta['source'] ?? null) : null;
    }

    /**
     * The rules' flags on a held post, so the moderator sees why it is here. Re-run now, deterministic, no network.
     *
     * @return list<array{code: string, severity: string, text: string}>
     */
    public function postFlags(PulsePost $post): array
    {
        $class = (string) (config('pulse.entities.rules_class') ?: ContentGate::LOCAL_RULES);

        if (! class_exists($class) || $post->author === null) {
            return [];
        }

        try {
            return array_values((array) (app($class)->assess((string) $post->body, $post->author, [
                'kind' => 'post', 'title' => (string) $post->title, 'exclude_post_id' => $post->id,
            ])['flags'] ?? []));
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** A held post was approved or rejected: audit it and tell the author in plain words. */
    public function postDecided(User $moderator, PulsePost $post, bool $approved, ?string $reason = null): void
    {
        AuditLog::record($moderator, $approved ? 'post.approve' : 'post.reject', $post, $approved ? [] : ['reason' => $reason]);

        if ($post->author !== null) {
            $this->tell($post->author, $approved
                ? ['type' => 'post_decision', 'title' => 'Your post is live', 'body' => 'A person checked it and approved it. It shows in the feed now.']
                : ['type' => 'post_decision', 'title' => 'Your post was not published', 'body' => 'Reason: '.$reason]);
        }
    }

    // ---------------------------------------------------------------- listing decisions

    /**
     * Approve, hold or remove an offer, role, need or page. Holding and removing need a reason, which the author reads.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function decideListing(User $moderator, Model $subject, string $decision, ?string $reason = null): void
    {
        $this->assertPlatformModerator($moderator);

        $reason = $reason !== null ? trim($reason) : null;

        if (in_array($decision, ['held', 'removed'], true) && ($reason === null || $reason === '')) {
            throw ValidationException::withMessages(['reason' => 'Say why. The person who made it will read this.']);
        }

        $this->listings->decide($moderator, $subject, $decision, $reason ?: null);

        $type = array_search($subject::class, self::LISTING_TYPES, true) ?: 'listing';
        $author = $this->authorOf($subject);

        if ($author !== null) {
            $this->tell($author, $this->listingNotice($type, $this->titleOf($subject), $decision, $reason));
        }
    }

    /**
     * Approve every held listing the rules would let through today. Pending items (no rules ran) and anything the
     * rules still flag stay for a person. Returns how many were approved.
     */
    public function approveClean(User $moderator): int
    {
        $this->assertPlatformModerator($moderator);

        $count = 0;

        foreach ($this->listings()->where('moderation', 'held') as $item) {
            $why = $this->why($item['model'], $item['author']);

            if ($why['flags'] !== [] || $why['source'] === 'no_rules') {
                continue;
            }

            $this->decideListing($moderator, $item['model'], 'approved');
            $count++;
        }

        return $count;
    }

    // ---------------------------------------------------------------- reports

    /**
     * Close every open report about one thing. A platform moderator goes through ReportService::resolve for each, so
     * a confirmed spam report shrinks the sender's knock budget. The reporters hear the outcome (ReportService), and
     * when the report is upheld the person responsible hears it too, in plain words.
     *
     * @return int reports closed
     */
    public function closeReports(User $moderator, Model $target, bool $confirmed, ?string $resolution = null): int
    {
        $this->assertPlatformModerator($moderator);

        $open = PulseReport::query()->open()
            ->where('reportable_type', $target::class)
            ->where('reportable_id', $target->getKey())
            ->get();

        foreach ($open as $report) {
            $this->reports->resolve($moderator, $report, $confirmed, $resolution);
        }

        if ($confirmed && $open->isNotEmpty() && ($user = $this->responsibleFor($target)) !== null) {
            $spam = $open->contains('reason', 'spam');
            $this->tell($user, [
                'type' => 'report_upheld',
                'title' => 'A report about your '.self::reportedNoun($target).' was upheld',
                'body' => 'A person looked at it and agreed.'.($spam && $this->hasBudget($target) ? ' You have fewer first contacts for a while. The allowance heals by itself when you stay clean.' : '')
                    .($resolution ? ' Note from the moderator: '.$resolution : ''),
            ]);
        }

        return $open->count();
    }

    /** Hold or remove a reviewed review that a report or the rules put in front of a person. */
    public function decideReview(User $moderator, PulseReview $review, bool $approve, ?string $reason = null): void
    {
        $this->assertPlatformModerator($moderator);

        $reason = $reason !== null ? trim($reason) : null;

        if (! $approve && ($reason === null || $reason === '')) {
            throw ValidationException::withMessages(['reason' => 'Say why. The writer will read this.']);
        }

        $this->reviews->moderate($moderator, $review, $approve);

        if (! $approve) {
            AuditLog::record($moderator, 'review.remove_reason', $review, ['reason' => $reason]);
        }

        $author = $review->author;

        if ($author !== null) {
            $this->tell($author, $approve
                ? ['type' => 'review_decision', 'title' => 'Your review is live', 'body' => 'A person checked it and approved it. It shows once both sides have written one, or after 14 days.']
                : ['type' => 'review_decision', 'title' => 'Your review was removed', 'body' => 'Reason: '.$reason]);
        }
    }

    // ---------------------------------------------------------------- words

    /** @return array{type: string, title: string, body: string} */
    private function listingNotice(string $type, string $title, string $decision, ?string $reason): array
    {
        $noun = self::noun($type);
        $quoted = '"'.mb_substr($title, 0, 80).'"';

        return match ($decision) {
            'approved' => ['type' => 'listing_decision', 'title' => "Your {$noun} {$quoted} is live", 'body' => 'A person checked it and approved it. It can show on the floor now.'],
            'held' => ['type' => 'listing_decision', 'title' => "We are looking at your {$noun} {$quoted}", 'body' => "It stays off the floor for now. Reason: {$reason}"],
            default => ['type' => 'listing_decision', 'title' => "Your {$noun} {$quoted} was removed", 'body' => "Reason: {$reason}"],
        };
    }

    /** @param  array{type: string, title: string, body: string}  $payload */
    private function tell(User $user, array $payload): void
    {
        try {
            $this->notifier->notify($user, 'system', $payload);
        } catch (Throwable $e) {
            report($e); // the decision is saved; a notice that fails must not undo it
        }
    }

    public static function noun(string $type): string
    {
        return match ($type) {
            'entity' => 'business page',
            default => $type,
        };
    }

    public static function reportedNoun(Model $target): string
    {
        return match (true) {
            $target instanceof PulseMessage => 'message',
            $target instanceof PulseConversation => 'request',
            $target instanceof PulseReview => 'review',
            $target instanceof PulseEntity => 'business page',
            $target instanceof PulseOffer => 'offer',
            $target instanceof PulseRole => 'role',
            $target instanceof PulseNeed => 'need',
            $target instanceof PulseSignal => 'listing',
            $target instanceof User => 'profile',
            default => 'post',
        };
    }

    private function hasBudget(Model $target): bool
    {
        return $target instanceof PulseConversation || $target instanceof PulseMessage || $target instanceof User;
    }

    private function authorOf(Model $subject): ?User
    {
        return match (true) {
            $subject instanceof PulseNeed => $subject->user,
            $subject instanceof PulseEntity, $subject instanceof PulseOffer, $subject instanceof PulseRole => $subject->creator,
            default => null,
        };
    }

    /** The person a confirmed report is about, when there is one to tell. */
    private function responsibleFor(Model $target): ?User
    {
        return match (true) {
            $target instanceof PulseMessage => $target->author,
            $target instanceof PulseConversation => $target->initiator,
            $target instanceof PulseReview => $target->author,
            $target instanceof User => $target,
            $target instanceof PulseSignal => $target->owner,
            default => $this->authorOf($target),
        };
    }

    private function titleOf(Model $subject): string
    {
        return (string) ($subject->title ?? $subject->name ?? 'Untitled');
    }

    private function assertPlatformModerator(User $user): void
    {
        if (! $user->isPlatformModerator()) {
            throw new AuthorizationException('Only moderators can do that.');
        }
    }
}
