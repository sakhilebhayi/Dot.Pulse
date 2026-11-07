<?php

namespace App\Services\Pulse\Lines;

use App\Models\CommunityMembership;
use App\Models\PulseAuditEntry;
use App\Models\PulseBlock;
use App\Models\PulseComment;
use App\Models\PulseFollower;
use App\Models\PulseMatch;
use App\Models\PulseMedia;
use App\Models\PulsePost;
use App\Models\PulseReaction;
use Illuminate\Support\Facades\DB;
use App\Models\PulseConversation;
use App\Models\PulseDoor;
use App\Models\PulseEntityBlock;
use App\Models\PulseEntityMember;
use App\Models\PulseMessage;
use App\Models\PulseMute;
use App\Models\PulseNeed;
use App\Models\PulseNotificationPref;
use App\Models\PulseProfile;
use App\Models\PulseQuote;
use App\Models\PulseReport;
use App\Models\PulseReview;
use App\Models\PulseSave;
use App\Models\User;

/**
 * Everything Pulse holds that is about one person, for the privacy page ("download my data").
 *
 * Included: their profile and settings, notification preferences, consent ledger, blocks and mutes, saves, needs
 * and doors, pages they belong to, every line they were part of (their own messages in full; the other side's
 * messages as the person saw them; the other side shown by public name only, an anonymous professional's
 * identity never), quotes, reviews they wrote, reports they filed (reason and status, not moderator notes), and
 * the audit rows where they were the actor (action and time), their community posts, comments and memberships, the
 * counts of follows and reactions, their photos (metadata), and the notifications Pulse sent them.
 * Excluded: the other party's email, phone, account details and private settings, other people's reports about
 * them, moderator notes, and the identity of anyone who was anonymous to them.
 */
class PrivacyExport
{
    public function __construct(private readonly ConsentLedger $ledger, private readonly LinePresenter $presenter) {}

    /** @return array<string, mixed> JSON serialisable */
    public static function forUser(User $user): array
    {
        return app(self::class)->build($user);
    }

    /** @return array<string, mixed> */
    public function build(User $user): array
    {
        $profile = PulseProfile::query()->where('user_id', $user->id)->first();

        return [
            'generated_at' => now()->toIso8601String(),
            'account' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'profile' => $profile?->only([
                'headline', 'bio', 'location', 'website', 'skills', 'expertise_tags', 'kind', 'discoverability', 'open_to',
                'reach', 'show_place', 'experience_years', 'languages', 'interests', 'achievements', 'work_history',
                'contact_policy', 'age_confirmed_at', 'onboarded_at',
            ]),
            'notification_preferences' => PulseNotificationPref::query()->where('user_id', $user->id)->first()?->only([
                'channels', 'categories', 'frequency', 'digest', 'digest_hour', 'timezone', 'quiet_start', 'quiet_end',
            ]),
            'consent' => array_map(fn ($row) => [
                'sentence' => $row['sentence'],
                'since' => $row['since']?->toIso8601String(),
                'until' => $row['until']?->toIso8601String(),
            ], $this->ledger->entries($user)),
            'blocks' => [
                'people_you_blocked' => PulseBlock::query()->where('blocker_id', $user->id)->count(),
                'pages_you_blocked' => PulseEntityBlock::query()->where('user_id', $user->id)->where('side', PulseEntityBlock::BY_USER)->count(),
            ],
            'mutes' => PulseMute::query()->where('user_id', $user->id)->get(['kind', 'target', 'created_at'])->toArray(),
            'saves' => PulseSave::query()->where('user_id', $user->id)->get(['saveable_type', 'saveable_id', 'created_at'])->toArray(),
            'needs' => PulseNeed::query()->where('user_id', $user->id)->get(['id', 'title', 'details', 'budget_text', 'status', 'created_at'])->toArray(),
            'doors' => PulseDoor::query()->where('owner_id', $user->id)->get(['id', 'purpose', 'length', 'status', 'opens_at', 'closes_at'])->toArray(),
            'pages' => PulseEntityMember::query()->where('user_id', $user->id)->with('entity:id,name,slug')->get()
                ->map(fn ($m) => ['page' => $m->entity?->name, 'role' => $m->role, 'since' => $m->created_at?->toIso8601String()])->all(),
            'lines' => $this->lines($user),
            'reviews_written' => PulseReview::query()->where('user_id', $user->id)->get(['rating', 'body', 'pseudonymous', 'moderation', 'created_at', 'revealed_at'])->toArray(),
            'reports_filed' => PulseReport::query()->where('reporter_id', $user->id)->get(['reportable_type', 'reason', 'details', 'status', 'created_at'])->toArray(),
            'posts' => PulsePost::withTrashed()->where('user_id', $user->id)->orderBy('id')->get(['id', 'community_id', 'type', 'title', 'body', 'status', 'created_at'])->toArray(),
            'comments' => PulseComment::withTrashed()->where('user_id', $user->id)->orderBy('id')->get(['id', 'pulse_post_id', 'body', 'created_at'])->toArray(),
            'communities' => CommunityMembership::query()->where('user_id', $user->id)->get(['community_id', 'role', 'created_at'])->toArray(),
            'follows' => ['following' => PulseFollower::query()->where('follower_id', $user->id)->count(), 'followers' => PulseFollower::query()->where('following_id', $user->id)->count()],
            'reactions' => PulseReaction::query()->where('user_id', $user->id)->count(),
            'matches' => ['kept' => PulseMatch::query()->where('user_id', $user->id)->whereNull('dismissed_at')->count()],
            'photos' => PulseMedia::query()->where('user_id', $user->id)->get(['id', 'purpose', 'mime', 'width', 'height', 'bytes', 'created_at'])->toArray(),
            'notifications' => DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)->orderBy('created_at')->get(['type', 'data', 'read_at', 'created_at'])
                ->map(fn ($n) => ['type' => class_basename($n->type), 'data' => json_decode((string) $n->data, true), 'read_at' => $n->read_at, 'at' => $n->created_at])->all(),
            'team_invitations' => DB::table('pulse_entity_invitations')->where('email_hash', \App\Services\Pulse\Entities\InvitationService::hash((string) $user->email))
                ->orderBy('id')->get(['role', 'expires_at', 'accepted_at', 'declined_at', 'revoked_at', 'created_at'])->map(fn ($i) => (array) $i)->all(),
            'activity_log' => PulseAuditEntry::query()->where('actor_id', $user->id)->orderBy('id')->get(['action', 'subject_type', 'subject_id', 'created_at'])->toArray(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function lines(User $user): array
    {
        $conversations = PulseConversation::query()->visibleTo($user)
            ->with(['initiator:id,name', 'recipient:id,name', 'entity:id,name', 'initiatorEntity:id,name'])
            ->orderBy('id')->get();

        $quotes = PulseQuote::query()->whereIn('pulse_conversation_id', $conversations->pluck('id'))->get()->groupBy('pulse_conversation_id');

        return $conversations->map(function (PulseConversation $c) use ($user, $quotes) {
            $side = Access::side($user, $c);
            $messages = PulseMessage::query()->where('pulse_conversation_id', $c->id)->orderBy('id')->get();
            $counterpart = $this->presenter->counterpart($c, $side);

            return [
                'id' => $c->id,
                'kind' => $c->kind,
                'status' => $c->status,
                'about' => $c->context_title,
                'card' => $c->card,
                'other_side' => $counterpart['kind'] === 'anonymous' ? 'An anonymous professional' : $counterpart['name'],
                'started_at' => $c->created_at?->toIso8601String(),
                'outcome' => $c->hasConfirmedOutcome() ? $c->outcome : null,
                'messages' => $messages->map(fn (PulseMessage $m) => [
                    'from' => $m->kind === 'system' ? 'Pulse' : ($m->user_id === $user->id ? 'You' : $this->presenter->label($c, $m->side, $side)),
                    'at' => $m->created_at?->toIso8601String(),
                    'text' => $m->erased_at ? null : $m->body,
                ])->all(),
                'quotes' => ($quotes[$c->id] ?? collect())->map(fn (PulseQuote $q) => $q->only([
                    'amount_cents', 'amount_max_cents', 'currency', 'earliest_date', 'included', 'excluded', 'valid_until', 'status',
                ]))->all(),
            ];
        })->all();
    }
}
